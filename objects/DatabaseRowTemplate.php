<?php

namespace PHPerformance\Objects;

use InvalidArgumentException;

/**
 * abstract class containing core functions that all db objects must implement for consistency
 */
abstract class DatabaseRowTemplate {
    protected array $rowData;

    public function get(string $key) {
        if (empty($key)) {
            throw new InvalidArgumentException("Key cannot be empty.");
        }

        // if field does not exist, returns null
        return $this->rowData[$key];
    }
    
    /**
     * Retrieve a copy of the object's current row data. Can be modified without affecting the object
     * @return array
     */
    public function getRowData(): array
    {
        return $this->rowData;
    }

    /**
     * Returns a reference to the object's current row data. Great for speeds of array access without doubling the memory consumption,
     * but updating values will affect the class data. This is intended for fast reads only
     * @return array
     */
    public function &getRowDataReference(): array
    {
        return $this->rowData;
    }

    /**
     * generic function to allow making edits to row data. Note that this currently allows you to add columns that are not already present.
     * Do not rely on that behavior, as it will disappear when proper validations are implemented
     * @param string $key
     * @param mixed $value
     * @throws \InvalidArgumentException
     * @return void
     */
    public function updateColumn(string $key, mixed $value): void
    {
        // TODO: value validations
        if (empty($key)) {
            throw new InvalidArgumentException("Key cannot be empty.");
        }

        $this->rowData[$key] = $value;
    }

    abstract public static function constructWithDbOutput(array $row): DatabaseRowTemplate;
    abstract public static function constructWithId(int $id): DatabaseRowTemplate;
}
