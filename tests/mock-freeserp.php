<?php
/**
 * Мок FreeSerp для локальних тестів: 60 детермінованих «AI-сайтів» у форматі
 * відповіді index=sites (частина без DR, різні стеки й ніші, у описі — <script>
 * для перевірки, що фронтенд не виконує HTML із даних).
 * Запуск: php -S 127.0.0.1:9001 tests/mock-freeserp.php
 */
header('Content-Type: application/json');
$niches = ['AI Agents & Autonomous','Video Generation','Code & Dev Tools','Marketing & Ads','Voice & Text-to-Speech','AI Website Builder','Productivity'];
$stacks = ['nextjs','lovable','ai_likely','wordpress',null,'v0','bolt'];
$all = [];
for ($i = 0; $i < 60; $i++) {
  $all[] = [
    'domain' => "site$i-".($i%3?'ai':'labs').'.com', 'url' => "https://site$i.example",
    'title' => "Site $i — ".($i%2?'Agents that do the boring work':'Generate videos from text &amp; images'),
    'ai_summary' => str_repeat("Site $i is an AI-powered tool for teams. ", $i%4+2).'<script>alert(1)</script>',
    'ai_categories' => [$niches[$i%7], $niches[($i+3)%7]],
    'ai_source' => $stacks[$i%7], 'dr' => $i%3===0 ? null : ($i*7)%90,
    'went_live' => date('Y-m-d', strtotime('2026-09-08 -'.$i.' days')),
  ];
}
$r = $all;
if (!empty($_GET['ai_categories'])) $r = array_values(array_filter($r, fn($x)=>in_array($_GET['ai_categories'],$x['ai_categories'],true)));
if (!empty($_GET['ai_source'])) $r = array_values(array_filter($r, fn($x)=>$x['ai_source']===$_GET['ai_source']));
if (!empty($_GET['dr_min'])) $r = array_values(array_filter($r, fn($x)=>($x['dr']??-1)>=(int)$_GET['dr_min']));
if (!empty($_GET['from_date'])) $r = array_values(array_filter($r, fn($x)=>$x['went_live']>=$_GET['from_date'] && $x['went_live']<=($_GET['to_date']??'9999')));
if (!empty($_GET['q'])) $r = array_values(array_filter($r, fn($x)=>stripos($x['title'].$x['ai_summary'], $_GET['q'])!==false));
if (($_GET['sort']??'')==='dr') usort($r, fn($a,$b)=>($b['dr']??-1)<=>($a['dr']??-1));
$from=(int)($_GET['from']??0); $size=(int)($_GET['size']??20);
echo json_encode(['ok'=>true,'index'=>'sites','total'=>count($r),'results'=>array_slice($r,$from,$size)]);
