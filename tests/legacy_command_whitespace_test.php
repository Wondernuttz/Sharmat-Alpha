<?php
// Exercise the actual production loop without loading the application's services.
$source = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../functions.php'));
if (!preg_match('/        foreach \(\$actions as \$__laN => \$__laAction\) \{.*?^        \}/ms', $source, $match)) {
    throw new RuntimeException('Legacy command loop not found');
}
$loop = $match[0];
$apply = static function (array $actions) use ($loop): array {
    eval($loop);
    return $actions;
};
$cases = [
    ["actor|command|ExtCmdHug@target\n", 'actor|command|ExtCmdHug@target@legacy'],
    ["actor|command|ExtCmdKiss@target\r\n", 'actor|command|ExtCmdKiss@target@legacy'],
    ["actor|command|ExtCmdHug@target \t", 'actor|command|ExtCmdHug@target@legacy'],
    ['actor|command|ExtCmdHug@target', 'actor|command|ExtCmdHug@target@legacy'],
    ['actor|command|ExtCmdHug', 'actor|command|ExtCmdHug@legacy'],
    ['actor|command|ExtCmdHug@target@legacy', 'actor|command|ExtCmdHug@target@legacy'],
    ['actor|command|ExtCmdHug@target@LEGACY', 'actor|command|ExtCmdHug@target@LEGACY'],
    ["actor|command|OtherCommand@target\n", "actor|command|OtherCommand@target\n"],
    ['incomplete|action', 'incomplete|action'],
];
foreach ($cases as $i => [$input, $expected]) {
    if ($apply([$input]) !== [$expected]) {
        throw new RuntimeException('Legacy whitespace regression, case ' . $i);
    }
}
echo 'PASS: ' . count($cases) . " production-loop cases (LF, CRLF, whitespace, unchanged and unrelated commands).\n";
