<?php
/** PostgreSQL adapter for the finite MySQL query forms used by Wildlife Sentinel.
 * MySQL installations continue to use the unmodified native PDO connection.
 */
class WildlifePostgresPDO extends PDO
{
    private const CONFLICT_KEYS = [
        'ranger_availability'=>'ranger_id', 'ranger_live_tracking'=>'ranger_id',
        'scout_live_tracking'=>'scout_id', 'user_preferences'=>'user_id',
        'settings'=>'setting_key', 'admin_ai_settings'=>'admin_id',
        'zone_ai_settings'=>'zone_id', 'zone_system_settings'=>'zone_id',
        'zone_notification_settings'=>'zone_id', 'simulation_controls'=>'zone_id',
    ];

    /** Balanced SQL argument splitter; respects quoted strings and nested calls. */
    private static function args(string $text): array {
        $parts=[]; $start=0; $depth=0; $quote=null;
        for ($i=0, $len=strlen($text); $i<$len; $i++) {
            $c=$text[$i];
            if ($quote !== null) {
                if ($c==='\\') { $i++; continue; }
                if ($c===$quote) { if (($text[$i+1]??'')===$quote) $i++; else $quote=null; }
            } elseif ($c==="'" || $c==='"' || $c==='`') $quote=$c;
            elseif ($c==='(') $depth++;
            elseif ($c===')') $depth--;
            elseif ($c===',' && $depth===0) { $parts[]=trim(substr($text,$start,$i-$start)); $start=$i+1; }
        }
        $parts[]=trim(substr($text,$start)); return $parts;
    }

    private static function functions(string $sql): string {
        $out=''; $len=strlen($sql);
        for ($i=0; $i<$len;) {
            $c=$sql[$i];
            if ($c==="'" || $c==='"' || $c==='`') {
                $start=$i++; $quote=$c;
                while ($i<$len) {
                    if ($sql[$i]==='\\') { $i+=2; continue; }
                    if ($sql[$i++]===$quote) { if (($sql[$i]??'')===$quote) $i++; else break; }
                }
                $literal=substr($sql,$start,$i-$start);
                $out .= $quote==='`' ? '"'.substr($literal,1,-1).'"' : $literal; continue;
            }
            if (preg_match('/\G(DATE_SUB|DATE_ADD|TIMESTAMPDIFF|GROUP_CONCAT|IFNULL|SUM)\s*\(/Ai',$sql,$m,0,$i)) {
                $name=strtoupper($m[1]); $start=$i+strlen($m[0]); $j=$start; $depth=1; $quote=null;
                for (; $j<$len; $j++) {
                    $d=$sql[$j];
                    if ($quote!==null) { if ($d==='\\') $j++; elseif ($d===$quote) { if (($sql[$j+1]??'')===$quote) $j++; else $quote=null; } }
                    elseif ($d==="'" || $d==='"') $quote=$d;
                    elseif ($d==='(') $depth++;
                    elseif ($d===')' && --$depth===0) break;
                }
                $inside=self::functions(substr($sql,$start,$j-$start)); $a=self::args($inside);
                if ($name==='DATE_SUB' || $name==='DATE_ADD') {
                    if (count($a)!==2 || !preg_match('/^INTERVAL\s+(.+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)$/is',$a[1],$v)) throw new LogicException('Unsupported interval: '.$inside);
                    $out.='('.$a[0].($name==='DATE_SUB'?' - ':' + ').'('.$v[1].") * INTERVAL '1 ".strtolower($v[2])."')";
                } elseif ($name==='TIMESTAMPDIFF') {
                    $unit=strtoupper($a[0]); $seconds=['SECOND'=>1,'MINUTE'=>60,'HOUR'=>3600,'DAY'=>86400];
                    if (!isset($seconds[$unit])) throw new LogicException('Unsupported timestamp unit');
                    $out.='TRUNC(EXTRACT(EPOCH FROM (('.$a[2].') - ('.$a[1].'))) / '.$seconds[$unit].')';
                } elseif ($name==='GROUP_CONCAT') {
                    if (preg_match('/^(DISTINCT\s+)?(.+?)\s+SEPARATOR\s+(\'.*\')$/is',$inside,$g)) $out.='string_agg('.($g[1]??'').$g[2].'::text, '.$g[3].')';
                    else $out.="string_agg(($inside)::text, ',')";
                } elseif ($name==='IFNULL') $out.='COALESCE('.$inside.')';
                elseif (preg_match('/^[\w.]+\s*(?:=|<>|!=)\s*\'.*\'$/s',$inside)) $out.='SUM(('.$inside.')::integer)';
                else $out.='SUM('.$inside.')';
                $i=$j+1; continue;
            }
            $out.=$c; $i++;
        }
        return $out;
    }

    public static function translate(string $sql): string {
        $sql=self::functions(trim($sql));
        $sql=preg_replace('/\bDATABASE\(\)/i','current_schema()',$sql);
        if (preg_match('/^SHOW COLUMNS FROM "?(\w+)"?/i',$sql,$m)) {
            return "SELECT column_name AS \"Field\", data_type AS \"Type\", is_nullable AS \"Null\", column_default AS \"Default\" FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='".$m[1]."' ORDER BY ordinal_position";
        }
        if (preg_match('/^INSERT\s+IGNORE\s+/i',$sql)) $sql=preg_replace('/^INSERT\s+IGNORE\s+/i','INSERT ',$sql).' ON CONFLICT DO NOTHING';
        if (stripos($sql,'ON DUPLICATE KEY UPDATE')!==false) {
            preg_match('/^INSERT\s+INTO\s+"?(\w+)"?/i',$sql,$table);
            $key=self::CONFLICT_KEYS[$table[1]??'']??null;
            if (!$key) throw new LogicException('Unsupported upsert table: '.($table[1]??''));
            [$insert,$updates]=preg_split('/ON DUPLICATE KEY UPDATE/i',$sql,2);
            $updates=preg_replace('/\bVALUES\((\w+)\)/i','EXCLUDED.$1',$updates);
            $updates=preg_replace('/\b('.preg_quote($key,'/').')\s*=\s*\1\b/i','$1 = EXCLUDED.$1',$updates);
            $sql=$insert.' ON CONFLICT ('.$key.') DO UPDATE SET '.$updates;
        }
        if (preg_match('/^CREATE TABLE|^ALTER TABLE/i',$sql)) {
            $sql=preg_replace('/\bINT\(\d+\)/i','INTEGER',$sql);
            $sql=preg_replace('/\bTINYINT(?:\(\d+\))?/i','SMALLINT',$sql);
            $sql=preg_replace('/\bDATETIME\b/i','TIMESTAMP',$sql);
            $sql=preg_replace('/\bAUTO_INCREMENT\b/i','GENERATED BY DEFAULT AS IDENTITY',$sql);
            $sql=preg_replace('/\bENUM\([^)]*\)/i','TEXT',$sql);
            $sql=preg_replace('/\s+ON UPDATE CURRENT_TIMESTAMP/i','',$sql);
            $sql=preg_replace('/,\s*(?:INDEX|KEY)\s+\w+\s*\([^)]*\)/i','',$sql);
            $sql=preg_replace('/\s*ENGINE\s*=\s*\w+.*$/is','',$sql);
        }
        if (preg_match('/^DELETE a FROM audit_logs a\s+LEFT JOIN users u ON a.user_id = u.id\s+(WHERE .*)$/is',$sql,$d)) {
            $sql='DELETE FROM audit_logs WHERE id IN (SELECT a.id FROM audit_logs a LEFT JOIN users u ON a.user_id=u.id '.$d[1].')';
        }
        if (preg_match('/^UPDATE alarm_triggers at\s+JOIN alarm_systems a ON at.alarm_id = a.id\s+SET (.*?)\s+WHERE (.*)$/is',$sql,$j)) {
            $set=preg_replace('/\bat\.(\w+)\s*=/','$1 =',$j[1]);
            $sql='UPDATE alarm_triggers at SET '.$set.' FROM alarm_systems a WHERE at.alarm_id=a.id AND '.$j[2];
        }
        // MySQL permits a derived distance alias in HAVING without GROUP BY.
        if (preg_match('/\s+HAVING\s+distance\s*<\s*\?\s*(ORDER BY.*)$/is',$sql,$m,PREG_OFFSET_CAPTURE)) {
            $base=substr($sql,0,$m[0][1]); $tail=$m[1][0];
            $tail=preg_replace('/\bi\.severity\b/','severity',$tail);
            $sql='SELECT * FROM ('.$base.') AS nearby WHERE distance < ? '.$tail;
        }
        // PostgreSQL does not permit qualified target columns in UPDATE SET.
        if (preg_match('/^UPDATE\s+(\w+)\s+(\w+)\s+SET\s+/i',$sql,$u)) {
            $sql=preg_replace('/(SET\s+|,\s*)'.preg_quote($u[2],'/').'\.(\w+)\s*=/i','$1$2 =',$sql);
        }
        return $sql;
    }

    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(self::translate($query),$options);
    }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (preg_match("/^\\s*SELECT COUNT\\(\\*\\) AS c FROM users WHERE role = 'admin' FOR UPDATE\\s*$/i", $query)) {
            // PostgreSQL cannot lock aggregate results. Lock before taking the count
            // so first-admin registration keeps the original concurrency protection.
            parent::exec('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            $query=preg_replace('/\\s+FOR UPDATE\\s*$/i', '', $query);
        }
        $query=self::translate($query);
        return $fetchMode===null ? parent::query($query) : parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    public function exec(string $statement): int|false { return parent::exec(self::translate($statement)); }
    public function lastInsertId(?string $name=null): string|false {
        return $name!==null ? parent::lastInsertId($name) : (string)parent::query('SELECT lastval()')->fetchColumn();
    }
}
