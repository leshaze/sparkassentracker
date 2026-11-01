<?php
/**
 * Konfiguration Sparkassentracker (PHP-Version)
 * Alles Weitere (Passwort, Kategorien, Datenbank) liegt im data/-Ordner.
 */
return [
    'app_name'               => 'Sparkassentracker',

    // Datenverzeichnis – liegt bewusst AUSSERHALB von public/
    'data_dir'               => __DIR__ . '/data',

    // Übertragserkennung: max. Tage zwischen Abgang und Eingang auf zwei
    // verschiedenen eigenen Konten (Kreditkartenausgleich bis zu 8 Tage versetzt)
    'transfer_max_days'      => 14,

    // Überträge auf Konten vom Typ "Sparkonto" (Tagesgeld, Depot …) zählen in der
    // Ansicht "Alle Konten" als Ausgabe in dieser Kategorie (Unterkategorie = Kontoname),
    // Entnahmen als Einnahme – statt sich mit der Gegenbuchung aufzuheben.
    'spar_kategorie'         => 'Sparen & Anlage',

    // Umsätze pro Seite in der Liste
    'page_size'              => 200,

    // Login bleibt so lange gültig (Sekunden)
    'session_lifetime'       => 60 * 60 * 24 * 14,
];
