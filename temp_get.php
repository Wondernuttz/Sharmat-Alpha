<?php
require_once "../../lib/postgresql.class.php";
$d = new postgresql();
$r = $d->fetchOne("SELECT value FROM conf_opts WHERE id = 'aiagent_nsfw_prompts'");
if($r) {
    $j = json_decode($r["value"], true);
    foreach($j as $k=>$v) {
        if(strpos($k,"tier_prost")!==false) echo $k.": ".$v."\n\n";
    }
}
