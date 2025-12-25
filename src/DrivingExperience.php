<?php

class DrivingExperience
{
    public int $id;
    public string $date;
    public string $startTime;
    public string $endTime;
    public float $mileage;
    public int $weatherId;
    public int $trafficId;
    /**
     * @var int[]
     */
    public array $roadIds;
    public string $partOfDay;
    public int $visibilityId;
    public int $parkingId;
    public int $manoeuvreId;

    public function __construct(
        int $id = 0,
        string $date = '',
        string $startTime = '',
        string $endTime = '',
        float $mileage = 0.0,
        int $weatherId = 0,
        int $trafficId = 0,
        array $roadIds = [],
        string $partOfDay = '',
        int $visibilityId = 0,
        int $parkingId = 0,
        int $manoeuvreId = 0
    ) {
        $this->id = $id;
        $this->date = $date;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->mileage = $mileage;
        $this->weatherId = $weatherId;
        $this->trafficId = $trafficId;
        $this->roadIds = array_values(array_unique(array_map('intval', $roadIds)));
        $this->partOfDay = $partOfDay;
        $this->visibilityId = $visibilityId;
        $this->parkingId = $parkingId;
        $this->manoeuvreId = $manoeuvreId;
    }

    public static function fromRow(array $row): self
    {
        $rawRoads = $row['road_ids'] ?? [];
        if (is_string($rawRoads)) {
            $rawRoads = array_filter(explode(',', $rawRoads), 'strlen');
        } elseif (!is_array($rawRoads)) {
            $rawRoads = [$rawRoads];
        }

        return new self(
            (int)($row['id'] ?? 0),
            (string)($row['date'] ?? ''),
            (string)($row['start_time'] ?? ''),
            (string)($row['end_time'] ?? ''),
            (float)($row['mileage'] ?? 0),
            (int)($row['weather_id'] ?? 0),
            (int)($row['traffic_id'] ?? 0),
            $rawRoads,
            (string)($row['idOfDay'] ?? ''),
            (int)($row['visibility_id'] ?? 0),
            (int)($row['parking_id'] ?? 0),
            (int)($row['manoeuvre_id'] ?? 0)
        );
    }

    public static function fromForm(array $data, array $roadIds): self
    {
        return new self(
            (int)($data['id'] ?? 0),
            trim((string)($data['date'] ?? '')),
            trim((string)($data['start_time'] ?? '')),
            trim((string)($data['end_time'] ?? '')),
            (float)($data['mileage'] ?? 0),
            (int)($data['weather_id'] ?? 0),
            (int)($data['traffic_id'] ?? 0),
            $roadIds,
            trim((string)($data['partOfDay'] ?? $data['idOfDay'] ?? '')),
            (int)($data['visibility_id'] ?? 0),
            (int)($data['parking_id'] ?? 0),
            (int)($data['manoeuvre_id'] ?? 0)
        );
    }
}
