<?php
$reOpenStatuses = ['abierto','re-abierto','reabierto','devuelto','pendiente',
                   'en proceso','en producción','en produccion'];

function isReOpenStatus($status) {
    global $reOpenStatuses;
    $lower = strtolower($status);
    echo "Testing: '$lower'\n";
    foreach ($reOpenStatuses as $kw) {
        if (strpos($lower, $kw) !== false) {
            echo "Match found with: '$kw'\n";
            return true;
        }
    }
    return false;
}

$test = "En producción";
var_dump(isReOpenStatus($test));
?>
