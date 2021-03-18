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

namespace czechpmdevs\buildertools\async;

use czechpmdevs\buildertools\editors\Fixer;
use Error;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\MainLogger;
use pocketmine\world\format\io\BaseWorldProvider;
use pocketmine\world\format\io\region\Anvil;
use pocketmine\world\format\io\WorldProviderManager;
use function basename;
use function count;
use function explode;
use function glob;
use function is_dir;
use function microtime;
use function round;

class WorldFixTask extends AsyncTask {
    
    /** @var string */
    public string $worldPath;
    
    /** @var int */
    public int $percentage = 0;
    /** @var string */
    public string $error = "";

    /** @var float */
    public float $time = 0.0;
    /** @var int */
    public int $chunkCount = 0;

    /** @var bool */
    public bool $forceStop = false;

    public function __construct(string $worldPath) {
        $this->worldPath = $worldPath;
    }

    /** @noinspection PhpUnused */
    public function onRun(): void {
//        MainLogger::getLogger()->debug("[BuilderTools] Fixing world $this->worldPath...");

        if(!is_dir($this->worldPath)) {
            $this->error = "File not found";
            return;
        }

        $providerManager = new WorldProviderManager(); // TODO

        $providerClass = LevelProviderManager::getProvider($this->worldPath);
        if($providerClass === null) {
            $this->error = "Unknown provider";
            return;
        }

        try {
            /** @var BaseWorldProvider|Anvil|null $provider */
            $provider = new $providerClass($this->worldPath . DIRECTORY_SEPARATOR);
        }
        catch (Error $error) {
            $this->error = "Error while loading provider: {$error->getMessage()}";
            return;
        }

        if((!$provider instanceof Anvil)) { // TODO - LevelDB
            if($provider === null) {
                $this->error = "Unknown world provider.";
                return;
            }

            $this->error = "BuilderTools does not support fixing chunks with " . get_class($provider) . " provider.";
            return;
        }

        $fixer = new Fixer();

        $startTime = microtime(true);

        $chunksToFix = $this->getListOfChunksToFix($this->worldPath);
        foreach ($chunksToFix as $index => [$chunkX, $chunkZ]) {
            $chunk = $provider->loadChunk($chunkX, $chunkZ);
            if($chunk === null) {
                continue;
            }

            for($x = 0; $x < 16; ++$x) {
                for($z = 0; $z < 16; ++$z) {
                    for($y = 0; $y < $provider->getWorldHeight(); ++$y) {
                        if(($id = $chunk->getBlockId($x, $y, $z)) != 0) {
                            $data = $chunk->getBlockData($x, $y, $z);

                            $fixer->fixBlock($id, $data);

                            $chunk->setBlockId($x, $y, $z, $id);
                            $chunk->setBlockData($x, $y, $z, $data);
                        }
                    }
                }
            }

            $this->percentage = (($index + 1) * 100) / count($chunksToFix);

            $provider->saveChunk($chunk);
            $provider->doGarbageCollection();

//            MainLogger::getLogger()->debug("[BuilderTools] " . ($index + 1) . "/" . count($chunksToFix) . " chunks fixed!");
            if($this->forceStop) {
                return;
            }
        }

        $this->chunkCount = count($chunksToFix);
        $this->time = round(microtime(true) - $startTime);

        $this->percentage = -1;
        MainLogger::getLogger()->debug("[BuilderTools] World fixed in " . round(microtime(true)-$startTime) .", affected " .count($chunksToFix). " chunks!");
    }

    /**
     * @return int[][]
     */
    private function getListOfChunksToFix(string $worldPath): array {
        $regionPath = $worldPath . DIRECTORY_SEPARATOR . "region" . DIRECTORY_SEPARATOR;

        $files = glob($regionPath . "*.mca*");
        if(!$files) {
            return [];
        }

        $chunks = [];
        foreach ($files as $regionFilePath) {
            $split = explode(".", basename($regionFilePath));
            $regionX = (int)$split[1];
            $regionZ = (int)$split[2];

            $region = new RegionLoader($regionFilePath, $regionX, $regionZ);
            $region->open();

            for($x = 0; $x < 32; ++$x) {
                for($z = 0; $z < 32; ++$z) {
                    if($region->chunkExists($x, $z)) {
                        $chunks[] = [($regionX << 5) + $x, ($regionZ << 5) + $z];
                    }
                }
            }
        }

        return $chunks;
    }
}