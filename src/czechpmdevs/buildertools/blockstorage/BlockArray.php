<?php

/**
 * Copyright (C) 2018-2021  CzechPMDevs
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace czechpmdevs\buildertools\blockstorage;

use pocketmine\math\Vector3;
use pocketmine\world\ChunkManager;
use pocketmine\world\World;
use function array_slice;
use function count;
use function in_array;

class BlockArray implements UpdateLevelData {

    /** @var bool */
    protected bool $detectDuplicates;

    /** @var int[] */
    public array $blocks = [];
    /** @var int[] */
    public array $coords = [];

    /** @var int */
    public int $offset = 0;

    /**
     * Fields to avoid allocating memory every time
     * when writing or reading block from the
     * array
     */
    protected int $lastHash, $lastBlockHash;

    public function __construct(bool $detectDuplicates = false) {
        $this->detectDuplicates = $detectDuplicates;
    }

    /** @var BlockArraySizeData|null */
    protected ?BlockArraySizeData $sizeData = null;
    /** @var ChunkManager|null */
    protected ?ChunkManager $level = null;

    /**
     * Adds block to the block array
     *
     * @return $this
     */
    public function addBlock(Vector3 $vector3, int $id, int $meta): BlockArray {
        return $this->addBlockAt($vector3->getFloorX(), $vector3->getFloorY(), $vector3->getFloorZ(), $id, $meta);
    }

    /**
     * Adds block to the block array
     *
     * @return $this
     */
    public function addBlockAt(int $x, int $y, int $z, int $id, int $meta): BlockArray {
        $this->lastHash = World::blockHash($x, $y, $z);

        if($this->detectDuplicates && in_array($this->lastHash, $this->coords)) {
            return $this;
        }

        $this->coords[] = $this->lastHash;
        $this->blocks[] = $id << 4 | $meta;

        return $this;
    }

    /**
     * Returns if it is possible read next block from the array
     */
    public function hasNext(): bool {
        return $this->offset < count($this->blocks);
    }

    /**
     * Reads next block in the array
     */
    public function readNext(?int &$x, ?int &$y, ?int &$z, ?int &$id, ?int &$meta): void {
        $this->lastHash = $this->coords[$this->offset];
        $this->lastBlockHash = $this->blocks[$this->offset++];

        World::getBlockXYZ($this->lastHash, $x, $y, $z);
        $id = $this->lastBlockHash >> 4; $meta = $this->lastBlockHash & 0xf;
    }

    /**
     * @return int $size
     */
    public function size(): int {
        return count($this->coords);
    }

    /**
     * Adds Vector3 to all the blocks in BlockArray
     */
    public function addVector3(Vector3 $vector3): BlockArray {
        $floorX = $vector3->getFloorX();
        $floorY = $vector3->getFloorY();
        $floorZ = $vector3->getFloorZ();

        $blockArray = new BlockArray();
        $blockArray->blocks = $this->blocks;

        foreach ($this->coords as $hash) {
            World::getBlockXYZ($hash, $x, $y, $z);
            $blockArray->coords[] = World::blockHash(($floorX + $x), ($floorY + $y), ($floorZ + $z));
        }

        return $blockArray;
    }

    /**
     * Subtracts Vector3 from all the blocks in BlockArray
     */
    public function subtractVector3(Vector3 $vector3): BlockArray {
        return $this->addVector3($vector3->multiply(-1));
    }

    /**
     * @return BlockArraySizeData is used for calculating dimensions
     */
    public function getSizeData(): BlockArraySizeData {
        if($this->sizeData === null) {
            $this->sizeData = new BlockArraySizeData($this);
        }

        return $this->sizeData;
    }

    public function setLevel(?ChunkManager $level): self {
        $this->level = $level;

        return $this;
    }

    public function getLevel(): ?ChunkManager {
        return $this->level;
    }

    /**
     * @return int[]
     */
    public function getBlockArray(): array {
        return $this->blocks;
    }

    /**
     * @return int[]
     */
    public function getCoordsArray(): array {
        return $this->coords;
    }

    /**
     * Removes all the blocks whose were checked already
     * For cleaning duplicate cache use cancelDuplicateDetection()
     */
    public function cleanGarbage(): void {
        $this->coords = array_slice($this->coords, $this->offset);
        $this->blocks = array_slice($this->blocks, $this->offset);

        $this->offset = 0;
    }
}