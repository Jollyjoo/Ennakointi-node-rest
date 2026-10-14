<?php
// Shared helper functions for pxweb scripts. Wrapped in function_exists checks to avoid redeclaration.

if (!function_exists('logMessage')) {
    function logMessage($message) {
        $logFile = 'fetch-data.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
}

if (!function_exists('find_dimension_key')) {
    function find_dimension_key($data, $candidates = []) {
        if (!isset($data['dimension']) || !is_array($data['dimension'])) return null;
        foreach ($data['dimension'] as $key => $dim) {
            if (in_array($key, $candidates, true)) return $key;
            if (isset($dim['id']) && in_array($dim['id'], $candidates, true)) return $key;
            if (isset($dim['variableCode']) && in_array($dim['variableCode'], $candidates, true)) return $key;
            if (isset($dim['category']['label']) && is_array($dim['category']['label'])) {
                foreach ($dim['category']['label'] as $lbl) {
                    if (in_array($lbl, $candidates, true)) return $key;
                }
            }
        }
        return null;
    }
}

if (!function_exists('resolve_code_index')) {
    function resolve_code_index($tiedot_map, $code) {
        if (!$tiedot_map || !is_array($tiedot_map)) return null;
        if (isset($tiedot_map[$code])) return $tiedot_map[$code];
        foreach ($tiedot_map as $k => $v) {
            if (stripos($k, $code) !== false) return $v;
            if (preg_match('/' . preg_quote($code, '/') . '$/i', $k)) return $v;
        }
        return null;
    }
}

if (!function_exists('cast_numeric_or_null')) {
    function cast_numeric_or_null($val) {
        if ($val === null) return null;
        if (is_numeric($val)) return $val + 0;
        return null;
    }
}

if (!function_exists('dump_dimensions_for_debug')) {
    function dump_dimensions_for_debug($data) {
        if (!isset($data['dimension']) || !is_array($data['dimension'])) return;
        $out = [];
        foreach ($data['dimension'] as $k => $dim) {
            $labels = '';
            if (isset($dim['category']['label']) && is_array($dim['category']['label'])) {
                $labels = implode(',', array_slice($dim['category']['label'], 0, 5));
            }
            $out[] = "$k: labels={$labels}";
        }
        logMessage("Dimensions: " . implode(' | ', $out));
    }
}

if (!function_exists('bind_stmt_params')) {
    function bind_stmt_params($stmt, $params) {
        $types = '';
        $refs = [];
        foreach ($params as $i => $p) {
            if (is_null($p)) {
                $types .= 's';
                $refs[] = & $params[$i];
            } elseif (is_int($p)) {
                $types .= 'i';
                $refs[] = & $params[$i];
            } elseif (is_float($p) || is_double($p)) {
                $types .= 'd';
                $refs[] = & $params[$i];
            } else {
                $types .= 's';
                $refs[] = & $params[$i];
            }
        }
        array_unshift($refs, $types);
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('getRecentKuukaudet')) {
    function getRecentKuukaudet($n = 5) {
        $months = [];
        $base = strtotime(date('Y-m-01'));
        for ($i = 1; $i <= $n; $i++) {
            $months[] = date('Y', strtotime("-$i month", $base)) . 'M' . date('m', strtotime("-$i month", $base));
        }
        return array_reverse($months);
    }
}

?>