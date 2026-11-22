<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Services\SpotifySearch;

final class SpotifyController extends Controller
{
    /** GET /api/spotify/search?q=&genre=&limit= */
    public function search(Request $req): array
    {
        $input = Validator::validate($req->query, [
            'q'     => 'nullable|string|max:100',
            'genre' => 'nullable|string|max:60',
            'limit' => 'nullable|int|min:1|max:10',
        ]);
        $query = trim((string) ($input['q'] ?? ''));
        $genre = trim((string) ($input['genre'] ?? ''));

        if ($genre !== '' && !SpotifySearch::supportsGenre($genre)) {
            throw HttpException::invalid('Choose a supported music genre', ['genre' => 'Unsupported genre']);
        }
        if ($query === '' && $genre === '') {
            throw HttpException::invalid('Enter a search term or choose a genre', ['q' => 'Required']);
        }

        return SpotifySearch::search($query, $genre !== '' ? $genre : null, (int) ($input['limit'] ?? 6));
    }
}
