<?php

declare(strict_types=1);

// 手元の Redis（127.0.0.1 の 6379、cluster は 7001〜7003）や MySQL（3407 の cache データベース）を空にしたり書き込んだりするスクリプトが、最初に読む
if (getenv('PROBE_ALLOW_FLUSH') !== '1') {
    fwrite(STDERR, "このスクリプトは手元の Redis を flushAll したり、その設定（maxmemory など）を変えたり、MySQL の表を作り直したりする。捨ててよい環境なら PROBE_ALLOW_FLUSH=1 を付けて実行する。\n");
    exit(1);
}
