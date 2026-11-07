<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/xlsx.php';

/**
 * WorldCat Metadata API client (brief-bib search + MARC/MARCXML retrieval).
 * Uses cURL, as requested, and caches the OAuth token in the settings table.
 */

const OCLC_DEFAULT_BASE = 'https://metadata.api.oclc.org/worldcat';
const OCLC_DEFAULT_OAUTH = 'https://oauth.oclc.org/token';
const OCLC_DEFAULT_SCOPE = 'WorldCatMetadataAPI:view_brief_bib WorldCatMetadataAPI:manage_bibs';

function oclc_is_demo() {
    return setting_get('demo_mode', '0') === '1';
}

function oclc_configured() {
    return trim(setting_get('oclc_wskey', '')) !== '' && trim(setting_get('oclc_secret', '')) !== '';
}

function oclc_base_url() {
    return rtrim(setting_get('oclc_base_url', OCLC_DEFAULT_BASE) ?: OCLC_DEFAULT_BASE, '/');
}

function oclc_oauth_url() {
    return setting_get('oclc_oauth_url', OCLC_DEFAULT_OAUTH) ?: OCLC_DEFAULT_OAUTH;
}

/* -------------------------------------------------------------------------
 * OAuth token
 * ---------------------------------------------------------------------- */

function oclc_get_token($force = false) {
    $wskey = trim(setting_get('oclc_wskey', ''));
    $secret = trim(setting_get('oclc_secret', ''));
    $scope = trim(setting_get('oclc_scope', OCLC_DEFAULT_SCOPE)) ?: OCLC_DEFAULT_SCOPE;

    if ($wskey === '' || $secret === '') {
        throw new RuntimeException('OCLC WSKey credentials are not configured. Add them on the Settings page, or enable demo mode.');
    }

    $fingerprint = sha1($wskey . '|' . $scope);
    $cache = json_decode(setting_get('oclc_token_cache', ''), true);
    if (!$force && is_array($cache)
        && ($cache['fingerprint'] ?? '') === $fingerprint
        && !empty($cache['access_token'])
        && (int)($cache['expires'] ?? 0) > time() + 90) {
        return $cache['access_token'];
    }

    $fields = [
        'grant_type' => 'client_credentials',
        'scope'      => $scope,
    ];
    $principalId = trim(setting_get('oclc_principal_id', ''));
    $principalNs = trim(setting_get('oclc_principal_idns', ''));
    if ($principalId !== '') {
        $fields['principalID'] = $principalId;
    }
    if ($principalNs !== '') {
        $fields['principalIDNS'] = $principalNs;
    }

    $ch = curl_init(oclc_oauth_url());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $wskey . ':' . $secret,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: mirrorCAT/1.0',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Could not contact the OCLC OAuth service: ' . ($curlError ?: 'unknown cURL error'));
    }

    $data = json_decode($body, true);
    if ($status < 200 || $status >= 300) {
        $detail = '';
        if (is_array($data)) {
            $detail = $data['error_description'] ?? ($data['error'] ?? ($data['message'] ?? ''));
        }
        if ($detail === '') {
            $detail = oclc_short_body($body);
        }
        throw new RuntimeException('OCLC authentication failed (HTTP ' . $status . ')' . ($detail !== '' ? ': ' . $detail : '.'));
    }

    $token = is_array($data) ? (string)($data['access_token'] ?? '') : '';
    if ($token === '') {
        throw new RuntimeException('OCLC authentication returned no access token. Check the WSKey, secret and scopes.');
    }

    $expiresIn = (int)($data['expires_in'] ?? 1200);
    if ($expiresIn <= 0) {
        $expiresIn = 1200;
    }
    setting_set('oclc_token_cache', json_encode([
        'access_token' => $token,
        'expires'      => time() + $expiresIn,
        'fingerprint'  => $fingerprint,
    ]));

    return $token;
}

function oclc_clear_token() {
    setting_set('oclc_token_cache', '');
}

function oclc_token_info() {
    $cache = json_decode(setting_get('oclc_token_cache', ''), true);
    if (!is_array($cache) || empty($cache['access_token'])) {
        return null;
    }
    return [
        'expires' => (int)($cache['expires'] ?? 0),
        'minutes' => max(0, (int)round(((int)($cache['expires'] ?? 0) - time()) / 60)),
    ];
}

function oclc_short_body($body) {
    $text = trim(strip_tags((string)$body));
    if ($text === '') {
        return '';
    }
    return mb_substr(preg_replace('/\s+/', ' ', $text), 0, 300);
}

/* -------------------------------------------------------------------------
 * Query construction
 * ---------------------------------------------------------------------- */

function oclc_clean_value($value) {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return str_replace('"', '', $value);
}

/**
 * Build an OCLC query from a row, the column mapping and optional template/filter.
 * Templates support {Column Name} and the Snapicat-style "ti:{}" placeholders.
 */
function oclc_build_query(array $data, array $mapping, $template = '', $extra = '') {
    $template = trim((string)$template);
    $extra = trim((string)$extra);
    $query = '';

    if ($template !== '') {
        $query = oclc_fill_template($template, $data, $mapping);
    } else {
        $parts = [];
        $restricted = oclc_restricted_first_fields();
        foreach ($mapping as $column => $index) {
            $value = oclc_clean_value($data[$column] ?? '');
            if ($value === '' || $index === '') {
                continue;
            }
            if (count($parts) === 0 && in_array($index, $restricted, true)) {
                $parts[] = 'kw:book';
            }
            $parts[] = $index . ':' . $value;
        }
        $query = implode(' AND ', $parts);
    }

    if ($extra !== '') {
        $query = $query === '' ? $extra : $query . ' AND ' . $extra;
    }

    return trim($query);
}

function oclc_fill_template($template, array $data, array $mapping) {
    $template = preg_replace_callback('/\{\s*([^{}]*?)\s*\}/', function ($match) use ($data) {
        $name = trim($match[1]);
        if ($name === '') {
            return "\x02";
        }
        return oclc_clean_value($data[$name] ?? '');
    }, $template);

    // "ti:{}" uses the mapped column for the ti index (or a column named ti).
    $template = preg_replace_callback('/([A-Za-z][A-Za-z0-9]*)\s*([:=])\s*\x02/', function ($match) use ($data, $mapping) {
        $index = $match[1];
        $value = '';
        foreach ($mapping as $column => $mappedIndex) {
            if ($mappedIndex === $index) {
                $value = (string)($data[$column] ?? '');
                break;
            }
        }
        if ($value === '' && isset($data[$index])) {
            $value = (string)$data[$index];
        }
        return $index . $match[2] . oclc_clean_value($value);
    }, $template);

    return str_replace(["\x02", '{', '}'], '', $template);
}

/**
 * URL-encode a query while keeping the Snapicat-style %26 for ampersands.
 */
function oclc_encode_query($query) {
    $query = str_replace('&', '%26', $query);
    $encoded = rawurlencode($query);
    return str_replace(['%2526', '%3A', '%2F', '%28', '%29', '%5B', '%5D', '%22'], ['%26', ':', '/', '(', ')', '[', ']', '%22'], $encoded);
}

/* -------------------------------------------------------------------------
 * cURL batch transport
 * ---------------------------------------------------------------------- */

function oclc_run_multi(array $requests, $token) {
    $mh = curl_multi_init();
    $handles = [];
    $headerStore = [];
    $ids = [];

    foreach ($requests as $request) {
        $id = $request['id'];
        $headerStore[$id] = [];
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: ' . ($request['accept'] ?? 'application/json'),
                'User-Agent: mirrorCAT/1.0',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => (int)($request['timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headerStore, $id) {
                $trimmed = trim($line);
                if ($trimmed !== '' && strpos($trimmed, ':') !== false) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headerStore[$id][strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$id] = $ch;
        $ids[spl_object_id($ch)] = $id;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $id => $ch) {
        $body = curl_multi_getcontent($ch);
        $results[$id] = [
            'id'          => $id,
            'status'      => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body'        => $body === false ? '' : $body,
            'headers'     => $headerStore[$id] ?? [],
            'curl_error'  => curl_error($ch),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

/* -------------------------------------------------------------------------
 * Brief-bib record handling
 * ---------------------------------------------------------------------- */

function oclc_stringify($value) {
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $item) {
            $parts[] = is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_UNICODE);
        }
        return implode(', ', $parts);
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return $value === null ? '' : (string)$value;
}

function oclc_fields_from_record(array $record, array $mapping) {
    $fields = [];
    $apiMap = oclc_index_to_api();

    foreach ($mapping as $column => $index) {
        if (!isset($apiMap[$index])) {
            continue;
        }
        $apiField = $apiMap[$index];
        $fields[$column . '_new'] = oclc_stringify($record[$apiField] ?? '');
    }

    $extras = [
        'title'              => 'title_new',
        'creator'            => 'creator_new',
        'publisher'          => 'publisher_new',
        'publicationPlace'   => 'publicationPlace_new',
        'edition'            => 'edition_new',
        'generalFormat'      => 'generalFormat_new',
        'specificFormat'     => 'specificFormat_new',
        'isbns'              => 'isbns_new',
        'issns'              => 'issns_new',
        'language'           => 'language_new',
    ];

    foreach ($extras as $apiField => $newField) {
        if (!array_key_exists($newField, $fields)) {
            $fields[$newField] = oclc_stringify($record[$apiField] ?? '');
        }
    }

    if (!isset($fields['date_new'])) {
        $fields['date_new'] = oclc_stringify(
            $record['machineReadableDate'] ?? ($record['date'] ?? ($record['publicationDate'] ?? ''))
        );
    }

    foreach ($fields as $key => $value) {
        if (trim($value) === '') {
            unset($fields[$key]);
        }
    }

    return $fields;
}

function oclc_parse_success($body, array $mapping) {
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['error' => 'WorldCat returned an unreadable response.'];
    }
    if (isset($data['numberOfRecords']) && (int)$data['numberOfRecords'] === 0) {
        return ['error' => 'No matching records found in WorldCat.'];
    }
    if (empty($data['briefRecords']) || !is_array($data['briefRecords'])) {
        return ['error' => 'No matching records found in WorldCat.'];
    }

    $record = $data['briefRecords'][0];
    return [
        'oclc_number' => oclc_stringify($record['oclcNumber'] ?? ''),
        'fields'      => oclc_fields_from_record($record, $mapping),
        'raw'         => $record,
    ];
}

function oclc_parse_error_body($body, $status) {
    $data = json_decode((string)$body, true);
    if (is_array($data)) {
        $message = $data['detail'] ?? ($data['title'] ?? ($data['message'] ?? ($data['error_description'] ?? '')));
        if ($message !== '') {
            return oclc_stringify($message);
        }
    }
    $short = oclc_short_body($body);
    return 'WorldCat request failed (HTTP ' . $status . ')' . ($short !== '' ? ': ' . $short : '');
}

function oclc_save_row($id, $status, $fields, $raw, $oclcNumber, $query, $error) {
    $stmt = db()->prepare("UPDATE batch_rows SET
        result_json = ?, raw_json = ?, oclc_number = ?, search_query = ?,
        status = ?, error = ?, updated = CURRENT_TIMESTAMP
        WHERE id = ?");
    $stmt->execute([
        json_encode($fields ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        (string)$oclcNumber,
        (string)$query,
        $status,
        (string)$error,
        (int)$id,
    ]);
}

/* -------------------------------------------------------------------------
 * Search
 * ---------------------------------------------------------------------- */

/**
 * Search a set of batch rows against WorldCat.
 *
 * @return array{rows: array, halt: bool, message: string, remaining: int|null}
 */
function oclc_search_rows(array $batch, array $rows) {
    $mapping = $batch['mapping'] ?? [];
    $template = $batch['query_template'] ?? '';
    $extra = $batch['extra_query'] ?? '';
    $orderBy = $batch['order_by'] ?? 'bestMatch';
    if (!array_key_exists($orderBy, order_by_options())) {
        $orderBy = 'bestMatch';
    }

    $demo = oclc_is_demo();
    $prepared = [];
    $responseRows = [];

    foreach ($rows as $row) {
        $data = json_decode($row['data_json'] ?? '{}', true) ?: [];
        $query = oclc_build_query($data, $mapping, $template, $extra);
        if ($query === '') {
            $error = 'No searchable values. Map at least one column, or enter a query template.';
            oclc_save_row($row['id'], 'error', [], null, '', '', $error);
            $responseRows[] = [
                'id' => (int)$row['id'], 'status' => 'error', 'oclc_number' => '',
                'error' => $error, 'search_query' => '', 'fields' => [],
            ];
            continue;
        }
        $prepared[] = ['row' => $row, 'data' => $data, 'query' => $query];
    }

    if (empty($prepared)) {
        return ['rows' => $responseRows, 'halt' => false, 'message' => '', 'remaining' => null];
    }

    if ($demo) {
        foreach ($prepared as $item) {
            $record = oclc_demo_record($item['row'], $batch, $item['data']);
            $fields = oclc_fields_from_record($record, $mapping);
            $oclc = (string)$record['oclcNumber'];
            oclc_save_row($item['row']['id'], 'success', $fields, $record, $oclc, $item['query'], '');
            $responseRows[] = [
                'id' => (int)$item['row']['id'], 'status' => 'success', 'oclc_number' => $oclc,
                'error' => '', 'search_query' => $item['query'], 'fields' => $fields,
            ];
        }
        return ['rows' => $responseRows, 'halt' => false, 'message' => '', 'remaining' => null];
    }

    try {
        $token = oclc_get_token();
    } catch (Throwable $e) {
        return [
            'rows' => $responseRows,
            'halt' => true,
            'message' => $e->getMessage(),
            'remaining' => null,
        ];
    }

    $base = oclc_base_url();
    $requests = [];
    foreach ($prepared as $item) {
        $url = $base . '/search/brief-bibs?q=' . oclc_encode_query($item['query'])
            . '&orderBy=' . rawurlencode($orderBy);
        $requests[] = [
            'id' => (int)$item['row']['id'],
            'url' => $url,
            'accept' => 'application/json',
        ];
    }

    $pending = $requests;
    $responses = [];
    $remaining = null;
    $halt = false;
    $message = '';

    for ($attempt = 0; $attempt < 2 && !empty($pending); $attempt++) {
        $run = oclc_run_multi($pending, $token);
        $retry = [];

        foreach ($run as $id => $response) {
            if (isset($response['headers']['x-ratelimit-remaining-day']) && is_numeric($response['headers']['x-ratelimit-remaining-day'])) {
                $remaining = (int)$response['headers']['x-ratelimit-remaining-day'];
            }

            $status = $response['status'];
            if ($status === 401 && $attempt === 0) {
                $retry[] = $id;
                continue;
            }
            if (($status === 0 || $status >= 500) && $attempt === 0) {
                $retry[] = $id;
                continue;
            }
            $responses[$id] = $response;
        }

        if (!empty($retry)) {
            $needRefresh = false;
            foreach ($retry as $id) {
                if (($run[$id]['status'] ?? 0) === 401) {
                    $needRefresh = true;
                    break;
                }
            }
            if ($needRefresh) {
                try {
                    $token = oclc_get_token(true);
                } catch (Throwable $e) {
                    return [
                        'rows' => $responseRows,
                        'halt' => true,
                        'message' => $e->getMessage(),
                        'remaining' => $remaining,
                    ];
                }
            }
            $retrySet = array_flip($retry);
            $pending = array_values(array_filter($requests, function ($request) use ($retrySet) {
                return isset($retrySet[$request['id']]);
            }));
            if ($attempt === 0) {
                sleep(1);
            }
            continue;
        }

        $pending = [];
    }

    foreach ($pending as $request) {
        $responses[$request['id']] = [
            'id' => $request['id'], 'status' => 0, 'body' => '',
            'headers' => [], 'curl_error' => 'The WorldCat request timed out.',
        ];
    }

    $requestById = [];
    foreach ($requests as $request) {
        $requestById[$request['id']] = $request;
    }
    $preparedById = [];
    foreach ($prepared as $item) {
        $preparedById[(int)$item['row']['id']] = $item;
    }

    foreach ($responses as $id => $response) {
        if (!isset($preparedById[$id])) {
            continue;
        }
        $item = $preparedById[$id];
        $status = (int)$response['status'];
        $body = (string)$response['body'];

        if ($status >= 200 && $status < 300) {
            $parsed = oclc_parse_success($body, $mapping);
            if (isset($parsed['error'])) {
                oclc_save_row($id, 'error', [], null, '', $item['query'], $parsed['error']);
                $responseRows[] = [
                    'id' => (int)$id, 'status' => 'error', 'oclc_number' => '',
                    'error' => $parsed['error'], 'search_query' => $item['query'], 'fields' => [],
                ];
            } else {
                oclc_save_row($id, 'success', $parsed['fields'], $parsed['raw'], $parsed['oclc_number'], $item['query'], '');
                $responseRows[] = [
                    'id' => (int)$id, 'status' => 'success', 'oclc_number' => $parsed['oclc_number'],
                    'error' => '', 'search_query' => $item['query'], 'fields' => $parsed['fields'],
                ];
            }
            if ($status === 429) {
                $halt = true;
                $message = 'WorldCat daily request limit reached. Searches have been paused.';
            }
            continue;
        }

        $error = oclc_parse_error_body($body, $status);
        if ($status === 429) {
            $halt = true;
            $message = 'WorldCat daily request limit reached. Searches have been paused.';
            $error = $message;
        } elseif ($status === 401) {
            $halt = true;
            $message = 'WorldCat authentication failed. Check your WSKey and secret on the Settings page.';
            $error = $message;
        } elseif ($status === 0) {
            $error = 'Could not reach WorldCat' . (!empty($response['curl_error']) ? ': ' . $response['curl_error'] : '.');
        }
        oclc_save_row($id, 'error', [], null, '', $item['query'], $error);
        $responseRows[] = [
            'id' => (int)$id, 'status' => 'error', 'oclc_number' => '',
            'error' => $error, 'search_query' => $item['query'], 'fields' => [],
        ];
    }

    if ($remaining === 0) {
        $halt = true;
        if ($message === '') {
            $message = 'The daily WorldCat request quota is now exhausted.';
        }
    }
    if ($remaining !== null) {
        setting_set('last_remaining_day', (string)$remaining);
    }

    return [
        'rows' => $responseRows,
        'halt' => $halt,
        'message' => $message,
        'remaining' => $remaining,
    ];
}

function oclc_demo_record(array $row, array $batch, array $data) {
    $mapping = $batch['mapping'] ?? [];
    $byIndex = [];
    foreach ($mapping as $column => $index) {
        if (trim((string)($data[$column] ?? '')) !== '') {
            $byIndex[$index] = (string)$data[$column];
        }
    }

    $title = $byIndex['ti'] ?? '';
    if ($title === '') {
        foreach ($data as $value) {
            if (trim((string)$value) !== '') {
                $title = 'Demo record: ' . (string)$value;
                break;
            }
        }
    }
    if ($title === '') {
        $title = 'Demo title ' . (int)$row['row_num'];
    }

    $isbn = $byIndex['bn'] ?? '';
    $oclc = (string)(900000000 + (int)$row['id']);

    return [
        'oclcNumber'         => $oclc,
        'title'              => $title,
        'creator'            => $byIndex['au'] ?? 'Demo Author',
        'publisher'          => $byIndex['pb'] ?? 'Demo Publisher',
        'publicationPlace'   => $byIndex['pl'] ?? 'Boston',
        'date'               => $byIndex['yr'] ?? '2024',
        'machineReadableDate'=> $byIndex['yr'] ?? '2024',
        'publicationDate'    => $byIndex['yr'] ?? '2024',
        'language'           => $byIndex['la'] ?? 'eng',
        'isbns'              => $isbn !== '' ? [$isbn] : ['9780000000000'],
        'issns'              => [],
        'edition'            => 'Demo edition',
        'generalFormat'      => 'Book',
        'specificFormat'     => 'Print book',
        'mergedOclcNumbers'  => [],
        'demo'               => true,
    ];
}

/* -------------------------------------------------------------------------
 * MARC / MARCXML
 * ---------------------------------------------------------------------- */

function oclc_marcxml_records($content) {
    if (preg_match_all('/<(?:\w+:)?record\b.*?<\/(?:\w+:)?record>/s', (string)$content, $matches)) {
        return $matches[0];
    }
    return [];
}

function oclc_combine_marcxml(array $records) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<collection xmlns="http://www.loc.gov/MARC21/slim">' . "\n";
    foreach ($records as $record) {
        $xml .= '  ' . trim($record) . "\n";
    }
    $xml .= '</collection>' . "\n";
    return $xml;
}

/**
 * Fetch MARCXML/MARC for the supplied rows.
 *
 * @return array{content:string, format:string, requested:int, returned:int, errors:array}
 */
function oclc_generate_marc(array $rows, $format = 'marcxml') {
    $format = $format === 'marc' ? 'marc' : 'marcxml';
    $demo = oclc_is_demo();

    $valid = [];
    foreach ($rows as $row) {
        $number = trim((string)($row['oclc_number'] ?? ''));
        if ($number === '') {
            continue;
        }
        $valid[] = ['number' => $number, 'row' => $row];
    }

    if (empty($valid)) {
        return ['content' => '', 'format' => $format, 'requested' => 0, 'returned' => 0, 'errors' => ['No rows with an OCLC number were selected.']];
    }

    $records = [];
    $errors = [];

    if ($demo) {
        foreach ($valid as $item) {
            $records[] = oclc_demo_marc_record($item['row']);
        }
    } else {
        try {
            $token = oclc_get_token();
        } catch (Throwable $e) {
            return ['content' => '', 'format' => $format, 'requested' => count($valid), 'returned' => 0, 'errors' => [$e->getMessage()]];
        }

        $accept = $format === 'marcxml' ? 'application/marcxml+xml' : 'application/marc';
        $base = oclc_base_url();
        $requests = [];
        foreach ($valid as $item) {
            $requests[] = [
                'id' => $item['number'],
                'url' => $base . '/manage/bibs/' . rawurlencode($item['number']),
                'accept' => $accept,
                'timeout' => 45,
            ];
        }

        $pending = $requests;
        $responses = [];
        for ($attempt = 0; $attempt < 2 && !empty($pending); $attempt++) {
            $run = oclc_run_multi($pending, $token);
            $retry = [];
            foreach ($run as $id => $response) {
                if ($response['status'] === 401 && $attempt === 0) {
                    $retry[] = $id;
                    continue;
                }
                if (($response['status'] === 0 || $response['status'] >= 500) && $attempt === 0) {
                    $retry[] = $id;
                    continue;
                }
                $responses[$id] = $response;
            }
            if (!empty($retry)) {
                $needRefresh = false;
                foreach ($retry as $id) {
                    if (($run[$id]['status'] ?? 0) === 401) {
                        $needRefresh = true;
                    }
                }
                if ($needRefresh) {
                    try {
                        $token = oclc_get_token(true);
                    } catch (Throwable $e) {
                        return ['content' => '', 'format' => $format, 'requested' => count($valid), 'returned' => 0, 'errors' => [$e->getMessage()]];
                    }
                }
                $retrySet = array_flip($retry);
                $pending = array_values(array_filter($requests, function ($request) use ($retrySet) {
                    return isset($retrySet[$request['id']]);
                }));
                sleep(1);
                continue;
            }
            $pending = [];
        }

        foreach ($valid as $item) {
            $id = $item['number'];
            $response = $responses[$id] ?? null;
            if (!$response) {
                $errors[] = 'No response for OCLC ' . $id . '.';
                continue;
            }
            $status = (int)$response['status'];
            $body = (string)$response['body'];
            if ($status < 200 || $status >= 300 || trim($body) === '') {
                $errors[] = 'OCLC ' . $id . ': ' . oclc_parse_error_body($body, $status);
                continue;
            }
            if ($format === 'marcxml') {
                $extracted = oclc_marcxml_records($body);
                if (empty($extracted)) {
                    $errors[] = 'OCLC ' . $id . ': the response did not contain a MARCXML record.';
                    continue;
                }
                foreach ($extracted as $record) {
                    $records[] = $record;
                }
            } else {
                // If OCLC (or a proxy) returned XML despite the Accept header, convert it.
                if (preg_match('/^\s*<\?xml|^\s*<(?:\w+:)?record|^\s*<(?:\w+:)?collection/i', $body)) {
                    $converted = marcxml_to_iso2709($body);
                    if ($converted !== '') {
                        $records[] = $converted;
                        continue;
                    }
                }
                $records[] = $body;
            }
        }
    }

    if (empty($records)) {
        return ['content' => '', 'format' => $format, 'requested' => count($valid), 'returned' => 0, 'errors' => $errors];
    }

    if ($format === 'marcxml') {
        $content = oclc_combine_marcxml($records);
    } else {
        $content = '';
        foreach ($records as $record) {
            if (preg_match('/^\s*<(?:\w+:)?record/i', $record) || preg_match('/^\s*<\?xml/i', $record)) {
                $content .= marcxml_to_iso2709($record);
            } else {
                $content .= $record;
            }
        }
    }

    return [
        'content' => $content,
        'format' => $format,
        'requested' => count($valid),
        'returned' => count($records),
        'errors' => $errors,
    ];
}

function oclc_demo_marc_record(array $row) {
    $result = $row['result'] ?? [];
    $raw = [];
    if (!empty($row['raw_json'])) {
        $decoded = json_decode($row['raw_json'], true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    $title = $raw['title'] ?? ($result['title_new'] ?? 'Untitled');
    $creator = $raw['creator'] ?? ($result['creator_new'] ?? '');
    $publisher = $raw['publisher'] ?? ($result['publisher_new'] ?? '');
    $place = $raw['publicationPlace'] ?? ($result['publicationPlace_new'] ?? '');
    $date = $raw['date'] ?? ($result['date_new'] ?? '');
    $isbn = $raw['isbns'][0] ?? ($result['isbns_new'] ?? '');
    $oclc = (string)($row['oclc_number'] ?? '');

    $xml = '<record xmlns="http://www.loc.gov/MARC21/slim">';
    $xml .= '<leader>00000nam a2200000 a 4500</leader>';
    $xml .= '<controlfield tag="001">' . xml_escape($oclc) . '</controlfield>';
    $xml .= '<controlfield tag="003">OCoLC</controlfield>';
    if ($isbn !== '') {
        $xml .= '<datafield tag="020" ind1=" " ind2=" "><subfield code="a">' . xml_escape($isbn) . '</subfield></datafield>';
    }
    if ($creator !== '') {
        $xml .= '<datafield tag="100" ind1="1" ind2=" "><subfield code="a">' . xml_escape($creator) . '</subfield></datafield>';
    }
    $xml .= '<datafield tag="245" ind1="1" ind2="0"><subfield code="a">' . xml_escape($title) . '</subfield></datafield>';
    if ($publisher !== '' || $place !== '' || $date !== '') {
        $xml .= '<datafield tag="260" ind1=" " ind2=" ">';
        if ($place !== '') {
            $xml .= '<subfield code="a">' . xml_escape($place) . ($publisher !== '' ? ' :' : '') . '</subfield>';
        }
        if ($publisher !== '') {
            $xml .= '<subfield code="b">' . xml_escape($publisher) . ($date !== '' ? ',' : '') . '</subfield>';
        }
        if ($date !== '') {
            $xml .= '<subfield code="c">' . xml_escape($date) . '</subfield>';
        }
        $xml .= '</datafield>';
    }
    $xml .= '<datafield tag="500" ind1=" " ind2=" "><subfield code="a">Generated by mirrorCAT demo mode.</subfield></datafield>';
    $xml .= '</record>';

    return $xml;
}

/**
 * Convert a MARCXML document (single record or collection) into ISO 2709 records.
 */
function marcxml_to_iso2709($xmlContent) {
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$xmlContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        return '';
    }
    $xml->registerXPathNamespace('m', 'http://www.loc.gov/MARC21/slim');
    $recordNodes = $xml->xpath('//*[local-name()="record"]');
    if (empty($recordNodes)) {
        return '';
    }

    $out = '';
    foreach ($recordNodes as $record) {
        $leaderNodes = $record->xpath('./*[local-name()="leader"]');
        $leader = !empty($leaderNodes) ? (string)$leaderNodes[0] : '00000nam a2200000 a 4500';
        $leader = str_pad(substr($leader, 0, 24), 24, ' ');

        $directory = '';
        $data = '';
        $fields = $record->xpath('./*[local-name()="controlfield" or local-name()="datafield"]');
        foreach ($fields as $field) {
            $tag = str_pad((string)$field['tag'], 3, '0', STR_PAD_LEFT);
            $fieldData = '';

            if ($field->getName() === 'controlfield' || $field->localName === 'controlfield') {
                $fieldData = (string)$field;
            } else {
                $ind1 = substr((string)($field['ind1'] ?? ' '), 0, 1);
                $ind2 = substr((string)($field['ind2'] ?? ' '), 0, 1);
                $fieldData = ($ind1 === '' ? ' ' : $ind1) . ($ind2 === '' ? ' ' : $ind2);
                foreach ($field->xpath('./*[local-name()="subfield"]') as $subfield) {
                    $fieldData .= "\x1F" . (string)$subfield['code'] . (string)$subfield;
                }
            }
            $fieldData .= "\x1E";

            $start = strlen($data);
            $directory .= sprintf('%03s%04d%05d', $tag, strlen($fieldData), $start);
            $data .= $fieldData;
        }

        $baseAddress = 24 + strlen($directory) + 1;
        $recordLength = $baseAddress + strlen($data) + 1;
        $newLeader = sprintf('%05d', $recordLength)
            . substr($leader, 5, 7)
            . sprintf('%05d', $baseAddress)
            . substr($leader, 17, 7);

        $out .= $newLeader . $directory . "\x1E" . $data . "\x1D";
    }

    return $out;
}

/* -------------------------------------------------------------------------
 * Connection test
 * ---------------------------------------------------------------------- */

function oclc_test_connection() {
    if (oclc_is_demo()) {
        return ['success' => true, 'message' => 'Demo mode is enabled, so no live OCLC call was made.'];
    }
    try {
        $token = oclc_get_token(true);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }

    $info = oclc_token_info();
    $minutes = $info ? $info['minutes'] : 0;
    return [
        'success' => true,
        'message' => 'Authentication succeeded. Access token is valid for about ' . $minutes . ' more minutes.',
    ];
}
