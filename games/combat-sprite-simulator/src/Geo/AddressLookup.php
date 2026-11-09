<?php

namespace App\Geo;

use JumPHP\Http\Http;
use JumPHP\Http\HttpResponse;

/** Resolve a French address using IGN Geoplateforme without blocking the frame. */
final class AddressLookup
{
    public function __construct(private Http $http)
    {
    }

    /** @param callable(float, float, string):void $found
     *  @param callable(string):void $failed */
    public function search(string $address, callable $found, callable $failed): void
    {
        $address = trim($address);
        if (strlen($address) < 3 || strlen($address) > 180) {
            $failed('Enter an address between 3 and 180 characters.');
            return;
        }

        $url = 'https://data.geopf.fr/geocodage/search?'
            . http_build_query(['q' => $address, 'limit' => 1], '', '&', PHP_QUERY_RFC3986);

        $this->http->get($url)->then(
            static function (HttpResponse $response) use ($found, $failed): void {
                if (!$response->ok()) {
                    $failed('IGN geocoding HTTP ' . $response->status);
                    return;
                }
                try {
                    $json = $response->json();
                } catch (\Throwable $error) {
                    $failed('Invalid geocoding response.');
                    return;
                }
                $feature = $json['features'][0] ?? null;
                $point = $feature['geometry']['coordinates'] ?? null;
                if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                    $failed('Address not found.');
                    return;
                }
                $lon = (float) $point[0];
                $lat = (float) $point[1];
                if ($lat < 41.0 || $lat > 51.5 || $lon < -6.0 || $lon > 10.0) {
                    $failed('This prototype supports metropolitan France only.');
                    return;
                }
                $found($lat, $lon, (string)($feature['properties']['label'] ?? 'French address'));
            },
            static fn (\Throwable $error) => $failed('IGN geocoding unavailable: ' . $error->getMessage())
        );
    }
}
