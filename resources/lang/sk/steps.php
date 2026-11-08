<?php

declare(strict_types=1);

return [
    'types' => [
        'trigger' => [
            'label' => 'Spúšťač',
            'description' => 'Spúšťa pracovný postup: manuálne, udalosťou aplikácie, podľa plánu alebo webhookom.',
        ],
        'condition' => [
            'label' => 'Podmienka',
            'description' => 'Vetví podľa podmienky: pravda alebo nepravda.',
        ],
        'branch' => [
            'label' => 'Vetvenie',
            'description' => 'Vyberie prvý prípad, ktorého podmienka platí, inak predvolený.',
        ],
        'set_field' => [
            'label' => 'Nastaviť pole',
            'description' => 'Zapíše zapisovateľné pole subjektu.',
        ],
        'transition' => [
            'label' => 'Prechod životného cyklu',
            'description' => 'Požiada o prechod životného cyklu; rozhodne strážca životného cyklu.',
        ],
        'delay' => [
            'label' => 'Oneskorenie',
            'description' => 'Čaká určitý čas, do dátumu alebo do dátumového poľa.',
        ],
        'wait' => [
            'label' => 'Čakanie',
            'description' => 'Čaká na udalosť alebo kým platí podmienka.',
        ],
        'loop' => [
            'label' => 'Počítadlo opakovaní',
            'description' => 'Pokračuje v slučke najviac stanovený počet ráz.',
        ],
        'start_workflow' => [
            'label' => 'Spustiť pracovný postup',
            'description' => 'Spustí iný pracovný postup a voliteľne naň počká.',
        ],
        'end' => [
            'label' => 'Koniec',
            'description' => 'Ukončí pracovný postup.',
        ],
        'approval' => [
            'label' => 'Schválenie',
            'description' => 'Požiada o schválenie: pravidlá, fázy, delegovanie a platnosť.',
        ],
        'notify' => [
            'label' => 'Upozorniť',
            'description' => 'Pošle notifikáciu ľuďom.',
        ],
        'webhook' => [
            'label' => 'Webhook',
            'description' => 'Zaradí odchádzajúci webhook.',
        ],
    ],
    'outcomes' => [
        'next' => 'Ďalej',
        'true' => 'Pravda',
        'false' => 'Nepravda',
        'transitioned' => 'Prechod vykonaný',
        'denied' => 'Zamietnuté',
        'received' => 'Prijaté',
        'met' => 'Splnené',
        'timed_out' => 'Vypršalo',
        'continue' => 'Pokračovať',
        'exhausted' => 'Vyčerpané',
        'started' => 'Spustené',
        'completed' => 'Dokončené',
        'failed' => 'Zlyhalo',
        'cancelled' => 'Zrušené',
        'approved' => 'Schválené',
        'rejected' => 'Zamietnuté',
        'expired' => 'Vypršané',
        'queued' => 'Zaradené',
    ],
    'instances' => '{1} :count inštancia|[2,4] :count inštancie|[0,*] :count inštancií',
];
