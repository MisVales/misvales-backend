<?php

return [
    'networks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VPN_NETWORKS', ''))
    ))),
    'hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VPN_HOSTS', 'vpn.safeacces.lat'))
    ))),
];

