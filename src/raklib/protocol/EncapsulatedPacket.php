<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace raklib\protocol;

#ifndef COMPILE
use pocketmine\utils\Binary;

#endif

#include <rules/BinaryIO.h>

class EncapsulatedPacket{
	const RELIABILITY_SHIFT = 5;
	const RELIABILITY_FLAGS = 0b111 << self::RELIABILITY_SHIFT;
	
	const SPLIT_FLAG = 0b00010000;

	public $reliability;
	public $hasSplit = false;
	public $length = 0;
	public $messageIndex = null;
	public $orderIndex = null;
	public $orderChannel = null;
	public $splitCount = null;
	public $splitID = null;
	public $splitIndex = null;
	public $buffer;
	public $needACK = false;
	public $identifierACK = null;

	/**
	 * @param string $binary
	 * @param bool   $internal
	 * @param int    &$offset
	 *
	 * @return EncapsulatedPacket
	 */
	public static function fromBinary($binary, $internal = false, &$offset = null){

		$packet = new EncapsulatedPacket();

		$flags = ord($binary{0});
		$packet->reliability = $reliability = ($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT;
		$packet->hasSplit = $hasSplit = ($flags & self::SPLIT_FLAG) > 0;
		if($internal){
			$length = Binary::readInt(substr($binary, 1, 4));
			$packet->identifierACK = Binary::readInt(substr($binary, 5, 4));
			$offset = 9;
		}else{
			$length = (int) ceil(Binary::readShort(substr($binary, 1, 2)) / 8);
			$offset = 3;
			$packet->identifierACK = null;
		}

		if($reliability > PacketReliability::UNRELIABLE){
			if($reliability >= PacketReliability::RELIABLE and $reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT){
				$packet->messageIndex = Binary::readLTriad(substr($binary, $offset, 3));
				$offset += 3;
			}

			if($reliability <= PacketReliability::RELIABLE_SEQUENCED and $reliability !== PacketReliability::RELIABLE){
				$packet->orderIndex = Binary::readLTriad(substr($binary, $offset, 3));
				$offset += 3;
				$packet->orderChannel = ord($binary{$offset++});
			}
		}

		if($hasSplit){
			$packet->splitCount = Binary::readInt(substr($binary, $offset, 4));
			$offset += 4;
			$packet->splitID = Binary::readShort(substr($binary, $offset, 2));
			$offset += 2;
			$packet->splitIndex = Binary::readInt(substr($binary, $offset, 4));
			$offset += 4;
		}

		$packet->buffer = substr($binary, $offset, $length);
		$offset += $length;

		return $packet;
	}

	public function getTotalLength(){
		return 3 + strlen($this->buffer) + ($this->messageIndex !== null ? 3 : 0) + ($this->orderIndex !== null ? 4 : 0) + ($this->hasSplit ? 10 : 0);
	}

	/**
	 * @param bool $internal
	 *
	 * @return string
	 */
	public function toBinary($internal = false){
		return
			chr(($this->reliability << self::RELIABILITY_SHIFT) | ($this->hasSplit ? self::SPLIT_FLAG : 0)) .
			($internal ? Binary::writeInt(strlen($this->buffer)) . Binary::writeInt($this->identifierACK) : Binary::writeShort(strlen($this->buffer) << 3)) .
			($this->reliability > PacketReliability::UNRELIABLE ?
				($this->isReliable() ? Binary::writeLTriad($this->messageIndex) : "") .
				($this->isSequenced() ? Binary::writeLTriad($this->orderIndex) . chr($this->orderChannel) : "")
				: ""
			) .
			($this->hasSplit ? Binary::writeInt($this->splitCount) . Binary::writeShort($this->splitID) . Binary::writeInt($this->splitIndex) : "")
			. $this->buffer;
	}

	public function isReliable() : bool{
		return (
			$this->reliability === PacketReliability::RELIABLE or
			$this->reliability === PacketReliability::RELIABLE_ORDERED or
			$this->reliability === PacketReliability::RELIABLE_SEQUENCED or
			$this->reliability === PacketReliability::RELIABLE_WITH_ACK_RECEIPT or
			$this->reliability === PacketReliability::RELIABLE_ORDERED_WITH_ACK_RECEIPT
		);
	}
	
	public function isSequenced() : bool{
		return (
			$this->reliability === PacketReliability::UNRELIABLE_SEQUENCED or
			$this->reliability === PacketReliability::RELIABLE_ORDERED or
			$this->reliability === PacketReliability::RELIABLE_SEQUENCED or
			$this->reliability === PacketReliability::RELIABLE_ORDERED_WITH_ACK_RECEIPT
		);
	}

	public function __toString(){
		return $this->toBinary();
	}
}
