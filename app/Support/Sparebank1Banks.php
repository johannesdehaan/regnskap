<?php

namespace App\Support;

/**
 * finInst-koder slik den personlige klienten i SpareBank 1 bruker dem.
 * Feil kode gir access_denied, så tom verdi lar innloggingssiden spørre
 * hvilken bank kunden tilhører.
 */
class Sparebank1Banks
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            '' => 'La SpareBank 1 spørre under BankID',
            'fid-smn' => 'SpareBank 1 SMN',
            'fid-nordnorge' => 'SpareBank 1 Nord-Norge',
            'fid-ostlandet' => 'SpareBank 1 Østlandet',
            'fid-sorost' => 'SpareBank 1 Sørøst-Norge',
            'fid-srbank' => 'SpareBank 1 Sør-Norge',
            'fid-helgeland' => 'Helgeland Sparebank',
            'fid-gudbrandsdal' => 'SpareBank 1 Gudbrandsdal',
            'fid-hallingdalvaldres' => 'SpareBank 1 Hallingdal Valdres',
            'fid-lomogskjaak' => 'SpareBank 1 Lom og Skjåk',
            'fid-ringerikehadeland' => 'SpareBank 1 Ringerike Hadeland',
        ];
    }

    public static function allows(string $finInst): bool
    {
        return array_key_exists($finInst, self::options());
    }
}
