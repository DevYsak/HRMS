<?php

/*
|--------------------------------------------------------------------------
| Conexus HR register — Casual / Sick Leave (CSL) snapshot, leave year 2026/27
|--------------------------------------------------------------------------
|
| The HR register is the balance truth for 2026/27 (01 Jul 2026 – 30 Jun 2027).
| Read by `php artisan leave:conexus-reconcile`, which posts the differences to
| the leave ledger. Nothing here is a schedule: `credit` is the current-year
| CSL credit HR recorded at the snapshot — the policy says 12 days/year but
| not how they are released, so no further credit is implied.
|
|   available = credit + carry - used - encashed   (never floored)
|
| `emails` lists the live login first, then any aliases the register used.
| The checksums below are verified before the command does anything.
|
*/

return [
    'leave_year' => '2026/27',
    'source' => 'Conexus HR register reconciliation - 2026/27',

    'checksums' => [
        'credit' => 40.0,
        'carry' => 56.5,
        'used' => 51.0,
        'available' => 45.5,
        'rows' => 20,
    ],

    'employees' => [
        ['name' => 'Gayatri Chagan Navlakhe', 'emails' => ['carol@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 2.5, 'used' => 0, 'encashed' => 0, 'available' => 4.5],
        ['name' => 'Nikita Dalal', 'emails' => ['nikita@conexus-ns.com', 'Nikita.Dalal@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 2, 'encashed' => 0, 'available' => 1],
        ['name' => 'Hasan', 'emails' => ['harry@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 0, 'used' => 2, 'encashed' => 0, 'available' => 0],
        ['name' => 'Sunita', 'emails' => ['qa@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 7, 'used' => 0, 'encashed' => 0, 'available' => 9],
        ['name' => 'Reeba', 'emails' => ['reeba@businessenergyservices.co.uk'], 'credit' => 2, 'carry' => 0, 'used' => 2, 'encashed' => 0, 'available' => 0],
        ['name' => 'Abhishek Bhoir', 'emails' => ['abhishek.bhoir@conexus-ns.com'], 'credit' => 2, 'carry' => 7, 'used' => 7, 'encashed' => 0, 'available' => 2],
        ['name' => 'Sakshi', 'emails' => ['sakshi.dige@conexus-ns.com'], 'credit' => 2, 'carry' => 0, 'used' => 1, 'encashed' => 0, 'available' => 1],
        ['name' => 'Chaitanya', 'emails' => ['chaitanya.naik@conexus-ns.com'], 'credit' => 2, 'carry' => 4, 'used' => 2.5, 'encashed' => 0, 'available' => 3.5],
        ['name' => 'Shivani', 'emails' => ['shivani.shinde@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 3, 'encashed' => 0, 'available' => 0],
        ['name' => 'Sunil', 'emails' => ['sunil.yadav@conexus-ns.com'], 'credit' => 2, 'carry' => 0, 'used' => 0, 'encashed' => 0, 'available' => 2],
        ['name' => 'Valid', 'emails' => ['valid.shaikh@conexus-ns.com'], 'credit' => 2, 'carry' => 4, 'used' => 4, 'encashed' => 0, 'available' => 2],
        ['name' => 'Saad', 'emails' => ['saad.mulla@conexus-ns.com'], 'credit' => 2, 'carry' => 7, 'used' => 4.5, 'encashed' => 0, 'available' => 4.5],
        ['name' => 'Shivendra', 'emails' => ['shivendra.kadam@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 3, 'encashed' => 0, 'available' => 0],
        ['name' => 'Pratish', 'emails' => ['Pratish.kavade@conexus-ns.com'], 'credit' => 2, 'carry' => 8, 'used' => 5.5, 'encashed' => 0, 'available' => 4.5],
        // A real HR reconciliation result: shown as -1 (in red), never floored.
        ['name' => 'Shradha', 'emails' => ['shradha.kotak@conexus-ns.com'], 'credit' => 2, 'carry' => 0, 'used' => 3, 'encashed' => 0, 'available' => -1],
        ['name' => 'Ankita', 'emails' => ['ankita.rawat@conexus-ns.com'], 'credit' => 2, 'carry' => 5, 'used' => 0, 'encashed' => 0, 'available' => 7],
        ['name' => 'Digambar', 'emails' => ['digambar.shingate@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 3, 'encashed' => 0, 'available' => 0],
        ['name' => 'Yogesh', 'emails' => ['yogesh.sakpal@conexus-ns.com'], 'credit' => 2, 'carry' => 7, 'used' => 5, 'encashed' => 0, 'available' => 4],
        ['name' => 'Firoz', 'emails' => ['firoz.khan@conexus-ns.com'], 'credit' => 2, 'carry' => 1, 'used' => 2.5, 'encashed' => 0, 'available' => 0.5],
        ['name' => 'Mayuresh', 'emails' => ['mayuresh.mhatre@conexus-ns.com'], 'credit' => 2, 'carry' => 0, 'used' => 1, 'encashed' => 0, 'available' => 1],
    ],
];
