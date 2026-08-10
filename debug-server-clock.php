<?php
// READ-ONLY diagnostic — confirming the actual system/PHP clock on this
// VPS vs. what got stored as started_at for the stuck timers, to size the
// real offset before deciding how to fix the two active entries.
echo "PHP date() now: " . date('Y-m-d H:i:s T') . "\n";
echo "PHP default timezone: " . date_default_timezone_get() . "\n";
echo "gmdate() (UTC) now: " . gmdate('Y-m-d H:i:s') . "\n";
echo "shell `date -u`: " . trim(shell_exec('date -u') ?: '(exec disabled)') . "\n";
