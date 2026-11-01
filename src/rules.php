<?php
declare(strict_types=1);

/**
 * Keyword-basierte Kategorisierung, persistiert in categories.json.
 *
 * Dual-Format wie in der Desktop-App:
 *   "Lebensmittel": ["rewe", "edeka"]                         (flach)
 *   "Lebensmittel": {"_keywords": [...], "Bäcker": [...]}     (mit Unterkategorien)
 *
 * Matching: Leerzeichen im Keyword passen auf bis zu 40 beliebige Zeichen
 * (außer "|"), das längste passende Keyword gewinnt.
 *
 * Kombi-Regeln: "paypal + steam" passt, wenn ALLE Teile irgendwo in Empfänger,
 * Verwendungszweck oder Buchungstext vorkommen. Kombi-Regeln haben Vorrang vor
 * einfachen Stichworten (mehr Teile = spezifischer). Ein einzelnes "+" ohne
 * zweiten Teil ("disney+") bleibt ein normales Stichwort.
 */
final class Rules
{
    public const FALLBACK = 'Unbekannt';

    private string $path;
    /** @var array<string, array{_keywords: string[], subs: array<string, string[]>}> */
    private array $cats = [];
    private ?array $compiled = null;

    public function __construct(string $path)
    {
        $this->path = $path;
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) {
                throw new RuntimeException('categories.json ist kein gültiges JSON.');
            }
            $this->load($data);
        } else {
            $this->load(self::defaults());
            $this->save();
        }
    }

    public static function instance(): self
    {
        static $r = null;
        return $r ??= new self(cfg('data_dir') . '/categories.json');
    }

    public function load(array $data): void
    {
        $this->cats = [];
        foreach ($data as $cat => $val) {
            $cat = (string) $cat;
            $entry = ['_keywords' => [], 'subs' => []];
            if (is_array($val) && array_is_list($val)) {
                $entry['_keywords'] = self::cleanList($val);
            } elseif (is_array($val)) {
                foreach ($val as $k => $v) {
                    if ($k === '_keywords') {
                        $entry['_keywords'] = self::cleanList((array) $v);
                    } else {
                        $entry['subs'][(string) $k] = self::cleanList((array) $v);
                    }
                }
            }
            $this->cats[$cat] = $entry;
        }
        $this->compiled = null;
    }

    private static function cleanList(array $l): array
    {
        $out = [];
        foreach ($l as $kw) {
            $kw = self::normalize((string) $kw);
            if (trim($kw) !== '' && !in_array($kw, $out, true)) {
                $out[] = $kw;
            }
        }
        return $out;
    }

    /** Export im Dual-Format der Desktop-App. */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->cats as $cat => $e) {
            if (!$e['subs']) {
                $out[$cat] = $e['_keywords'];
            } else {
                $out[$cat] = ['_keywords' => $e['_keywords']] + $e['subs'];
            }
        }
        return $out;
    }

    public function save(): void
    {
        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $this->path . '.tmp';
        file_put_contents($tmp, $json . "\n", LOCK_EX);
        rename($tmp, $this->path);
        $this->compiled = null;
    }

    public function categories(): array
    {
        return $this->cats;
    }

    /** Liste für Auswahlfelder: ["Kategorie" => ["Sub1", ...]] */
    public function options(): array
    {
        $o = [];
        foreach ($this->cats as $cat => $e) {
            $o[$cat] = array_keys($e['subs']);
        }
        ksort($o, SORT_NATURAL | SORT_FLAG_CASE);
        return $o;
    }

    // ---- Bearbeiten -------------------------------------------------------

    public function addCategory(string $cat): void
    {
        $this->compiled = null;
        $cat = trim($cat);
        if ($cat !== '' && !isset($this->cats[$cat])) {
            $this->cats[$cat] = ['_keywords' => [], 'subs' => []];
        }
    }

    public function deleteCategory(string $cat): void
    {
        $this->compiled = null;
        unset($this->cats[$cat]);
    }

    public function addSub(string $cat, string $sub): void
    {
        $this->compiled = null;
        $cat = trim($cat);
        $sub = trim($sub);
        if ($cat === '') {
            return;
        }
        $this->addCategory($cat);
        if ($sub !== '' && $sub !== '_keywords' && !isset($this->cats[$cat]['subs'][$sub])) {
            $this->cats[$cat]['subs'][$sub] = [];
        }
    }

    public function deleteSub(string $cat, string $sub): void
    {
        $this->compiled = null;
        unset($this->cats[$cat]['subs'][$sub]);
    }

    public function addKeyword(string $cat, string $sub, string $kw): void
    {
        $this->compiled = null;
        $kw = self::normalize($kw);
        if (trim($kw) === '') {
            return;
        }
        $cat = trim($cat);
        $sub = trim($sub);
        if ($cat === '') {
            return;
        }
        $this->addCategory($cat);
        if ($sub !== '' && $sub !== '_keywords') {
            $this->addSub($cat, $sub);
            $list = &$this->cats[$cat]['subs'][$sub];
        } else {
            $list = &$this->cats[$cat]['_keywords'];
        }
        if (!in_array($kw, $list, true)) {
            $list[] = $kw;
        }
        unset($list);
    }

    public function removeKeyword(string $cat, string $sub, string $kw): void
    {
        $this->compiled = null;
        $kw = self::normalize($kw);
        if (!isset($this->cats[$cat])) {
            return;
        }
        if ($sub !== '') {
            $l = $this->cats[$cat]['subs'][$sub] ?? [];
            $this->cats[$cat]['subs'][$sub] = array_values(array_filter($l, fn ($k) => $k !== $kw));
        } else {
            $l = $this->cats[$cat]['_keywords'];
            $this->cats[$cat]['_keywords'] = array_values(array_filter($l, fn ($k) => $k !== $kw));
        }
    }

    // ---- Matching ---------------------------------------------------------

    /** Kleinschreibung; Kombi-Regeln einheitlich als "a + b". */
    public static function normalize(string $kw): string
    {
        $kw = mb_strtolower($kw);
        $parts = self::comboParts($kw);
        return $parts ? implode(' + ', $parts) : $kw;
    }

    /** Teile einer Kombi-Regel, oder null bei einem einfachen Stichwort. */
    public static function comboParts(string $kw): ?array
    {
        if (!str_contains($kw, '+')) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode('+', $kw)), fn ($p) => $p !== ''));
        return count($parts) >= 2 ? array_values(array_unique($parts)) : null;
    }

    public static function keywordPattern(string $kw): string
    {
        $trailing = preg_match('/\s$/u', $kw) === 1;
        $leading = preg_match('/^\s/u', $kw) === 1;
        $parts = preg_split('/\s+/u', trim($kw));
        $quoted = array_map(fn ($p) => preg_quote($p, '/'), $parts);
        $pat = implode('[^|]{1,40}?', $quoted);
        if ($leading) {
            $pat = '(?:^|[\s|])' . $pat;
        }
        if ($trailing) {
            $pat .= '(?=[\s|]|$)';
        }
        return '/' . $pat . '/iu';
    }

    /** @return array{0:int,1:int,2:string[],3:string,4:string} [Teile, Länge, Muster, Kategorie, Unterkategorie] */
    private static function compileOne(string $kw, string $cat, string $sub): array
    {
        $parts = self::comboParts($kw) ?? [$kw];
        $len = array_sum(array_map('mb_strlen', $parts));
        return [count($parts), $len, array_map([self::class, 'keywordPattern'], $parts), $cat, $sub];
    }

    private function compile(): array
    {
        $list = [];
        foreach ($this->cats as $cat => $e) {
            foreach ($e['_keywords'] as $kw) {
                $list[] = self::compileOne($kw, $cat, '');
            }
            foreach ($e['subs'] as $sub => $kws) {
                foreach ($kws as $kw) {
                    $list[] = self::compileOne($kw, $cat, $sub);
                }
            }
        }
        // Kombi-Regeln zuerst (mehr Teile), dann längstes Keyword → "longest keyword wins"
        usort($list, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        return $list;
    }

    /** @return array{0:string,1:string} [Kategorie, Unterkategorie] */
    public function match(string $text): array
    {
        $this->compiled ??= $this->compile();
        $text = mb_strtolower($text);
        foreach ($this->compiled as [, , $pats, $cat, $sub]) {
            foreach ($pats as $pat) {
                if (!preg_match($pat, $text)) {
                    continue 2;
                }
            }
            return [$cat, $sub];
        }
        return [self::FALLBACK, ''];
    }

    public static function defaults(): array
    {
        return [
            'Lebensmittel' => ['rewe', 'edeka', 'aldi', 'lidl', 'kaufland', 'netto', 'penny',
                'real', 'denns', 'alnatura', 'biomarkt', 'metro'],
            'Drogerie' => ['dm-drogerie', 'dm filiale', 'rossmann', 'mueller'],
            'Restaurant & Cafe' => ['restaurant', 'cafe', 'café', 'mcdonald', 'burger king', 'kfc',
                'subway', 'lieferando', 'wolt', 'uber eats', 'doener', 'döner', 'pizzeria', 'starbucks'],
            'Mobilität' => ['shell', 'aral', 'esso', 'total', 'tankstelle', 'db vertrieb',
                'deutsche bahn', 'bvg', 'vrr', 'kvb', 'ruhrbahn', 'flixbus', 'uber', 'freenow',
                'parkhaus', 'parkraum'],
            'Wohnen' => ['miete', 'stadtwerke', 'vonovia', 'lvm', 'gas', 'strom', 'wasser', 'telekom',
                'vodafone', '1und1', '1&1', 'o2', 'gez', 'rundfunk'],
            'Versicherung' => ['allianz', 'huk', 'axa', 'ergo', 'debeka', 'barmer', 'tk ',
                'techniker krankenkasse', 'aok', 'versicherung'],
            'Online-Shopping' => ['amazon', 'amzn', 'ebay', 'otto', 'zalando', 'paypal',
                'aliexpress', 'temu', 'shein'],
            'Abos & Streaming' => ['netflix', 'spotify', 'disney', 'amazon prime', 'apple.com/bill',
                'youtube premium', 'dazn', 'sky deutschland', 'patreon', 'github', 'openai',
                'anthropic', 'claude.ai'],
            'Gesundheit' => ['apotheke', 'arzt', 'praxis', 'zahnarzt', 'physio', 'klinik'],
            'Bargeld' => ['geldautomat', 'auszahlung', 'bargeldauszahlung', 'atm '],
            'Einkommen' => ['gehalt', 'lohn', 'honorar', 'gutschrift', 'erstattung'],
            'Sparen & Anlage' => ['depot', 'etf', 'trade republic', 'scalable', 'consorsbank'],
            'Familie & Kinder' => ['kita', 'kindergarten', 'schule', 'spielzeug'],
        ];
    }
}
