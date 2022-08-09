<?php

namespace modmore\Gitify;

use Symfony\Component\Uid\Uuid;

class UuidToObjectMapper
{
    private $map = [];
    private $file = GITIFY_WORKING_DIR . '.gitify_mapping';
    public function __construct()
    {
        if (file_exists($this->file)) {
            // Load the database
            $this->map = json_decode(file_get_contents($this->file), true);
        }
    }

    // May be multiple keys so don't want to rely on a single ID value
    function getUuid(string $class, $id): string
    {
        if ($uuid = $this->findUuid($class, $id)) {
            return $uuid;
        }

        $newId = Uuid::v4()->toRfc4122();
        $this->map[$newId] = ['class' => $class, 'id' => $id];
        $this->writeMap();
        return $newId;
    }

    function getId(string $uuid): string
    {
        if (!isset($this->map[$uuid])) {
            throw new \Exception("Cannot find mapping for '$uuid'");
        }
        return $this->map[$uuid]['id'];
    }

    protected function findUuid(string $class, $id): ?string
    {
        foreach ($this->map as $key => $item) {
            if ($item['class'] === $class && $item['id'] === $id) {
                return $key;
            }
        }
        return null;
    }

    function isUuid($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        return Uuid::isValid($string);
    }

    function addMapping($uuid, $class, $id)
    {
        if (array_key_exists($uuid, $this->map)) {
            throw new \Exception("UUID $uuid already exists");
        }

        if (($theUuid = $this->findUuid($class, $id)) !== null) {
            throw new \Exception("An entry for $class, $id already exists with UUID $theUuid");
        }

        $this->map[$uuid] = ['class' => $class, 'id' => $id];
        $this->writeMap();
    }

    private function writeMap()
    {
        file_put_contents($this->file, json_encode($this->map));
    }
}
