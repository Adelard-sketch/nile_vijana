<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

final class SpotifySearch
{
    private const GENRE_TAGS = [
        'Afrobeats' => 'afrobeats',
        'Highlife' => 'highlife',
        'Hiplife' => 'hiplife',
        'Asakaa (Drill)' => 'asakaa',
        'Afrobeat' => 'afrobeat',
        'Amapiano' => 'amapiano',
        'Afro-pop' => 'afropop',
        'Hip hop' => 'hip-hop',
        'Drill' => 'drill',
        'R&B' => 'r-n-b',
        'Gospel' => 'gospel',
        'Bongo Flava' => 'bongo-flava',
        'Dancehall' => 'dancehall',
        'Reggae' => 'reggae',
        'Traditional' => 'traditional',
        'Other' => 'other',
    ];

    public static function supportsGenre(string $genre): bool
    {
        return isset(self::GENRE_TAGS[$genre]);
    }

    public static function search(string $query, ?string $genre, int $limit = 6): array
    {
        $clientId = trim((string) config('spotify.client_id', ''));
        $clientSecret = trim((string) config('spotify.client_secret', ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new HttpException(
                503,
                'Spotify search is not configured. Add Spotify API credentials to backend/config/config.php.'
            );
        }

        $searchTerms = [];
        if ($query !== '') {
            $searchTerms[] = $query;
        }
        if ($genre !== null) {
            $searchTerms[] = 'genre:' . self::GENRE_TAGS[$genre];
        }

        $tokenResponse = self::request(
            'https://accounts.spotify.com/api/token',
            ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
                CURLOPT_USERPWD    => $clientId . ':' . $clientSecret,
                CURLOPT_HTTPAUTH   => CURLAUTH_BASIC,
            ],
            'authentication'
        );
        $accessToken = $tokenResponse['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            app_log('spotify', 'Spotify authentication response did not contain an access token');
            throw new HttpException(502, 'Spotify search is temporarily unavailable. Please try again.');
        }

        $searchUrl = 'https://api.spotify.com/v1/search?' . http_build_query([
            'q'     => implode(' ', $searchTerms),
            'type'  => 'track',
            'limit' => max(1, min($limit, 10)),
        ]);
        $response = self::request(
            $searchUrl,
            ['Accept: application/json', 'Authorization: Bearer ' . $accessToken],
            [],
            'track search'
        );
        $tracks = $response['tracks']['items'] ?? null;
        if (!is_array($tracks)) {
            app_log('spotify', 'Spotify search response did not contain track results');
            throw new HttpException(502, 'Spotify search returned an invalid response. Please try again.');
        }

        $results = [];
        foreach ($tracks as $track) {
            if (!is_array($track) || !is_string($track['id'] ?? null) || !preg_match('/^[A-Za-z0-9]+$/', $track['id'])) {
                continue;
            }
            if (!is_string($track['name'] ?? null) || $track['name'] === '') {
                continue;
            }

            $artists = [];
            foreach (($track['artists'] ?? []) as $artist) {
                if (is_array($artist) && is_string($artist['name'] ?? null) && $artist['name'] !== '') {
                    $artists[] = $artist['name'];
                }
            }
            $imageUrl = $track['album']['images'][0]['url'] ?? '';
            if (!is_string($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL) || parse_url($imageUrl, PHP_URL_SCHEME) !== 'https') {
                $imageUrl = '';
            }

            $results[] = [
                'id'        => $track['id'],
                'title'     => $track['name'],
                'artist'    => implode(', ', $artists),
                'image_url' => $imageUrl,
            ];
        }

        return [
            'data' => $results,
            'meta' => [
                'total' => (int) ($response['tracks']['total'] ?? count($results)),
                'limit' => max(1, min($limit, 10)),
            ],
        ];
    }

    private static function request(string $url, array $headers, array $options, string $operation): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpException(502, 'Spotify search is temporarily unavailable. Please try again.');
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        foreach ($options as $key => $value) {
            $curlOptions[$key] = $value;
        }
        curl_setopt_array($handle, $curlOptions);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if (!is_string($raw) || $status < 200 || $status >= 300) {
            app_log('spotify', "Spotify $operation request failed", [
                'http_status' => $status,
                'curl_error' => $curlError !== '' ? $curlError : null,
            ]);
            throw new HttpException(502, 'Spotify search is temporarily unavailable. Please try again.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            app_log('spotify', "Spotify $operation response was not valid JSON");
            throw new HttpException(502, 'Spotify search returned an invalid response. Please try again.');
        }
        return $decoded;
    }
}
