<?php

namespace Main\Dto;

class FileRes
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $section,
        public int $size,
        public string $mime,
        public string $extension,
        public string $hash,
        public bool $deduplicated,
        public bool $isPublic,
        public string $createdAt,
        public string $privateUrl,
        public ?string $publicUrl,
    ) {
    }
}
