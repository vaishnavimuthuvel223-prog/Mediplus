<?php
// =========================================================
// MediPlus - Travel & Reach-By Time Estimation
//
// Uses free, keyless public services so nothing here requires
// an API key from the hospital:
//   - Nominatim (OpenStreetMap) for geocoding a text address
//   - OSRM public demo router for real driving distance/duration
// Both are best-effort: if either is unreachable (no internet,
// rate limited, etc.) we fall back to a straight-line distance
// and the configurable average speed, so the feature never
// breaks the page - it just becomes an estimate.
//
// The hospital's own location is FIXED and configured once by
// the admin (Admin > Hospital Settings), pre-filled from the
// address supplied when this feature was built:
//   Government District Head Quarters Hospital, Kangayam
//   lat 11.0056419, lng 77.5602020
// =========================================================

require_once __DIR__ . '/functions.php';

/**
 * Geocode a free-text address into [lat, lng] using Nominatim.
 * Returns null on any failure (no network, no match, etc).
 */
function geocode_address($address) {
    $address = trim((string) $address);
    if ($address === '') return null;

    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . urlencode($address);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            // Nominatim's usage policy requires a descriptive User-Agent.
            'header' => "User-Agent: MediPlusHospitalSystem/1.0 (appointment-travel-estimate)\r\n",
            'timeout' => 4,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) return null;

    return [
        'latitude' => (float) $data[0]['lat'],
        'longitude' => (float) $data[0]['lon'],
    ];
}

/**
 * Returns the patient's cached [lat, lng], geocoding and
 * caching it on first use (or if the saved address changed
 * since it was last geocoded). Returns null if no address is
 * saved or geocoding is currently unavailable.
 */
function get_patient_coordinates($pdo, array $patient) {
    if (empty($patient['address'])) return null;

    $addressUnchanged = !empty($patient['geocoded_address']) && $patient['geocoded_address'] === $patient['address'];
    if ($addressUnchanged && $patient['latitude'] !== null && $patient['longitude'] !== null) {
        return ['latitude' => (float) $patient['latitude'], 'longitude' => (float) $patient['longitude']];
    }

    $geo = geocode_address($patient['address']);
    if (!$geo) return null;

    $update = $pdo->prepare("
        UPDATE patients SET latitude = :lat, longitude = :lng, geocoded_address = :addr
        WHERE patient_id = :id
    ");
    $update->execute([
        ':lat' => $geo['latitude'],
        ':lng' => $geo['longitude'],
        ':addr' => $patient['address'],
        ':id' => $patient['patient_id'],
    ]);

    return $geo;
}

/**
 * Returns the fixed hospital coordinates + address from settings.
 */
function get_hospital_location() {
    return [
        'name' => get_setting('hospital_name', 'MediPlus Hospital'),
        'address' => get_setting('hospital_address', ''),
        'latitude' => (float) get_setting('hospital_latitude', 0),
        'longitude' => (float) get_setting('hospital_longitude', 0),
    ];
}

/**
 * Straight-line (haversine) distance in km between two coordinates.
 */
function haversine_km($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

/**
 * Calls the public OSRM router for real driving distance/duration.
 * Returns ['distance_km' => float, 'duration_minutes' => float] or null.
 */
function fetch_osrm_route($lat1, $lon1, $lat2, $lon2) {
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=false',
        $lon1, $lat1, $lon2, $lat2
    );

    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 4]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;

    $data = json_decode($response, true);
    if (empty($data['routes'][0])) return null;

    return [
        'distance_km' => $data['routes'][0]['distance'] / 1000,
        'duration_minutes' => $data['routes'][0]['duration'] / 60,
    ];
}

/**
 * Full travel estimate from a patient's saved address to the
 * fixed hospital location. Tries a real routing service first,
 * falls back to straight-line distance / configured avg speed.
 * Returns null only if the patient has no usable coordinates.
 */
function get_travel_estimate($pdo, array $patient) {
    $patientGeo = get_patient_coordinates($pdo, $patient);
    if (!$patientGeo) return null;

    $hospital = get_hospital_location();
    if (!$hospital['latitude'] || !$hospital['longitude']) return null;

    $route = fetch_osrm_route($patientGeo['latitude'], $patientGeo['longitude'], $hospital['latitude'], $hospital['longitude']);

    if ($route) {
        return [
            'distance_km' => round($route['distance_km'], 1),
            'travel_minutes' => (int) ceil($route['duration_minutes']),
            'source' => 'routed',
        ];
    }

    // Fallback: straight-line distance and configured average speed.
    $km = haversine_km($patientGeo['latitude'], $patientGeo['longitude'], $hospital['latitude'], $hospital['longitude']);
    $avgSpeed = max(1, (float) get_setting('avg_travel_speed_kmph', 30));
    return [
        'distance_km' => round($km, 1),
        'travel_minutes' => (int) ceil(($km / $avgSpeed) * 60),
        'source' => 'estimated',
    ];
}

/**
 * Combines queue wait time with travel time into concrete
 * clock times the patient can act on:
 *   - expected_call_time: when their token will likely be called
 *   - reach_by_time: when they should aim to physically be at the hospital
 *   - leave_by_time: when they should leave home to make it
 *
 * $estimated_wait_minutes should come from estimate_waiting_time()/
 * the live queue position (patients ahead * avg consultation time).
 */
function calculate_reach_by_schedule($estimated_wait_minutes, $travel_minutes) {
    $checkinBuffer = (int) get_setting('checkin_buffer_minutes', 10);
    $now = new DateTime();

    $expectedCallTime = (clone $now)->modify('+' . max(0, (int) $estimated_wait_minutes) . ' minutes');
    $reachByTime = (clone $expectedCallTime)->modify('-' . $checkinBuffer . ' minutes');
    if ($reachByTime < $now) $reachByTime = clone $now;

    $leaveByTime = (clone $reachByTime)->modify('-' . max(0, (int) $travel_minutes) . ' minutes');
    if ($leaveByTime < $now) $leaveByTime = clone $now;

    return [
        'expected_call_time' => $expectedCallTime->format('h:i A'),
        'reach_by_time' => $reachByTime->format('h:i A'),
        'leave_by_time' => $leaveByTime->format('h:i A'),
        'leave_by_is_now' => $leaveByTime <= $now,
    ];
}
