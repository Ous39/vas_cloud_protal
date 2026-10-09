<?php
declare(strict_types=1);
// Page: ?page=ussd_quiz — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_ussd_menus'); ussd_quiz_tables(); $db=portal_pdo();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $do=(string)($_POST['do']??'');
        if ($do==='save_quiz') { $k=save_quiz($_POST); flash('success','Quiz saved.'); redirect('?page=ussd_quiz&quiz='.urlencode($k)); }
        if ($do==='save_question') { $k=save_quiz_question($_POST); flash('success','Question saved.'); redirect('?page=ussd_quiz&quiz='.urlencode($k)); }
        if ($do==='toggle_question') { $k=toggle_quiz_question((int)($_POST['id']??0)); redirect('?page=ussd_quiz&quiz='.urlencode($k)); }
        if ($do==='import_questions') { $n=import_quiz_questions(trim((string)($_POST['quiz_key']??'')),(string)($_POST['lines']??'')); flash('success',$n.' questions added.'); redirect('?page=ussd_quiz&quiz='.urlencode(trim((string)($_POST['quiz_key']??'')))); }
    }
    $quizzes=$db->query('SELECT * FROM ussd_quizzes ORDER BY title')->fetchAll();
    $key=trim((string)($_GET['quiz']??'')); if($key===''&&$quizzes) $key=$quizzes[0]['quiz_key'];
    $quiz=null; foreach($quizzes as $q0) if($q0['quiz_key']===$key) $quiz=$q0;
    $qs=[]; $editQ=null; $plays=[]; $stats=null; $top=[];
    if($quiz){
        $st=$db->prepare('SELECT * FROM ussd_quiz_questions WHERE quiz_key=? ORDER BY status, id'); $st->execute([$key]); $qs=$st->fetchAll();
        if(isset($_GET['edit'])) foreach($qs as $q1) if((int)$q1['id']===(int)$_GET['edit']) $editQ=$q1;
        $st=$db->prepare('SELECT * FROM ussd_quiz_plays WHERE quiz_key=? ORDER BY id DESC LIMIT 15'); $st->execute([$key]); $plays=$st->fetchAll();
        $st=$db->prepare("SELECT COUNT(*) n, ROUND(AVG(score),2) avg_score, SUM(won) winners, COUNT(DISTINCT msisdn) players FROM ussd_quiz_plays WHERE quiz_key=? AND created_at >= NOW() - INTERVAL 7 DAY"); $st->execute([$key]); $stats=$st->fetch();
        $st=$db->prepare('SELECT msisdn, SUM(score) pts, COUNT(*) games FROM ussd_quiz_plays WHERE quiz_key=? AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY msisdn ORDER BY pts DESC LIMIT 10'); $st->execute([$key]); $top=$st->fetchAll();
    }
    $activeQ=count(array_filter($qs,fn($q)=>$q['status']==='active'));
    layout_start('USSD Quiz'); ussd_subnav('ussd_quiz');
    ?>
    <div class="cardx">
        <h3><i class="fa-solid fa-circle-question me-2"></i>USSD Quiz</h3>
        <p class="text-muted">A quiz is a set of questions that customers play on a phone: each game picks a few at random, scores one point per right answer, and offers <i>Play again</i>. Put one into any menu with the <b>Quiz game</b> item type in the <a href="?page=ussd_menu">Menu Builder</a>. Try it in the <a href="?page=ussd_sim">Simulator</a> first.</p>
        <div class="d-flex flex-wrap gap-2 mb-2"><?php foreach($quizzes as $q0):?><a class="btn btn-sm <?=$q0['quiz_key']===$key?'btn-primary':'btn-outline-primary'?>" href="?page=ussd_quiz&quiz=<?=urlencode($q0['quiz_key'])?>"><?=e($q0['title'])?><?=$q0['status']==='inactive'?' (off)':''?></a><?php endforeach;?><?php if(!$quizzes):?><span class="text-muted">No quiz yet — create the first one below.</span><?php endif;?></div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-lg-4"><div class="cardx"><h3><?=$quiz?'Quiz settings':'New quiz'?></h3>
            <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_quiz">
                <label class="small text-muted mb-0">Key <small>(letters, digits, - _ ; can't be changed later)</small></label><input class="form-control mb-2" name="quiz_key" value="<?=e($quiz['quiz_key']??'')?>" <?=$quiz?'readonly':''?> placeholder="comium_trivia">
                <label class="small text-muted mb-0">Title shown on the phone</label><input class="form-control mb-2" name="title" value="<?=e($quiz['title']??'')?>" placeholder="Comium Daily Quiz">
                <div class="row g-2"><div class="col-6"><label class="small text-muted mb-0">Questions per game</label><input type="number" min="3" max="10" class="form-control mb-2" name="per_game" value="<?=e($quiz['per_game']??5)?>"></div>
                <div class="col-6"><label class="small text-muted mb-0">Score to win</label><input type="number" min="1" max="10" class="form-control mb-2" name="win_score" value="<?=e($quiz['win_score']??4)?>"></div></div>
                <label class="small text-muted mb-0">Message to winners <small>(shown on the result screen; the prize itself is given by you — winners are listed here)</small></label><input class="form-control mb-2" name="win_text" value="<?=e($quiz['win_text']??'')?>" placeholder="You win! Watch for our SMS.">
                <label class="small text-muted mb-0">Status</label><select class="form-select mb-2" name="status"><option value="active" <?=($quiz['status']??'active')==='active'?'selected':''?>>Active</option><option value="inactive" <?=($quiz['status']??'')==='inactive'?'selected':''?>>Off</option></select>
                <button class="btn btn-primary w-100">Save quiz</button></form>
            <?php if($quiz):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=ussd_quiz&quiz=">+ New quiz</a><?php endif;?>
        </div></div>
        <div class="col-lg-8">
        <?php if($quiz):?>
            <div class="cardx"><h3><?=$editQ?'Edit question':'Add a question'?></h3>
                <form method="post" class="row g-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="save_question"><input type="hidden" name="quiz_key" value="<?=e($key)?>"><input type="hidden" name="id" value="<?=e($editQ['id']??'')?>">
                    <div class="col-12"><input class="form-control" name="question" value="<?=e($editQ['question']??'')?>" placeholder="Question, e.g. What is the capital of The Gambia?"></div>
                    <?php for($n=1;$n<=4;$n++):?><div class="col-md-6"><div class="input-group"><span class="input-group-text"><?=$n?></span><input class="form-control" name="opt<?=$n?>" value="<?=e($editQ['opt'.$n]??'')?>" placeholder="Answer <?=$n?><?=$n>2?' (optional)':''?>"></div></div><?php endfor;?>
                    <div class="col-md-4"><label class="small text-muted mb-0">Number of the right answer</label><input type="number" min="1" max="4" class="form-control" name="correct" value="<?=e($editQ['correct']??'')?>"></div>
                    <div class="col-md-8 d-flex align-items-end gap-2"><button class="btn btn-primary"><?=$editQ?'Save changes':'Add question'?></button><?php if($editQ):?><a class="btn btn-outline-secondary" href="?page=ussd_quiz&quiz=<?=urlencode($key)?>">Cancel</a><?php endif;?><small class="text-muted">A question with its answers must fit one phone screen (<?=USSD_QUIZ_BLOCK_MAX?> characters).</small></div>
                </form>
                <details class="mt-3"><summary class="small">Add many at once</summary>
                    <form method="post" class="mt-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="import_questions"><input type="hidden" name="quiz_key" value="<?=e($key)?>">
                        <p class="small text-muted mb-1">One per line: <code>Question | answer 1 | answer 2 | answer 3 | answer 4 | number of the right answer</code> (2 to 4 answers). Any bad line stops the whole import and says which.</p>
                        <textarea class="form-control code mb-2" rows="5" name="lines" placeholder="Capital of The Gambia? | Dakar | Banjul | Serrekunda | 2"></textarea><button class="btn btn-outline-primary btn-sm">Add these questions</button></form></details>
            </div>
            <div class="cardx mt-3"><h3>Questions <small class="text-muted"><?=$activeQ?> active of <?=count($qs)?><?=$activeQ<(int)$quiz['per_game']?' — need at least '.(int)$quiz['per_game'].' active for a full game':''?></small></h3>
                <?php if(!$qs):?><p class="text-muted mb-0">No questions yet.</p><?php else:?><div class="table-scroll"><table class="table table-sm mb-0"><thead><tr><th>Question</th><th>Answers</th><th>Right</th><th></th></tr></thead><tbody>
                <?php foreach($qs as $q2):?><tr class="<?=$q2['status']==='inactive'?'text-muted':''?>"><td><?=e($q2['question'])?></td><td class="small"><?php foreach([1,2,3,4] as $n) if($q2['opt'.$n]!==null&&$q2['opt'.$n]!=='') echo $n.'. '.e($q2['opt'.$n]).'<br>';?></td><td><?=e($q2['correct'])?></td>
                    <td class="text-nowrap"><a class="btn btn-sm btn-outline-warning py-0" href="?page=ussd_quiz&quiz=<?=urlencode($key)?>&edit=<?=e($q2['id'])?>">Edit</a>
                    <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="do" value="toggle_question"><input type="hidden" name="id" value="<?=e($q2['id'])?>"><button class="btn btn-sm btn-outline-secondary py-0"><?=$q2['status']==='active'?'Turn off':'Turn on'?></button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
            </div>
            <div class="row g-3 mt-1"><div class="col-md-6"><div class="cardx"><h3>Last 7 days</h3>
                <?php if(!$stats||!$stats['n']):?><p class="text-muted mb-0">No games played yet.</p><?php else:?><p class="mb-1"><b><?=number_format((int)$stats['n'])?></b> games by <b><?=number_format((int)$stats['players'])?></b> players — average score <b><?=e($stats['avg_score'])?></b> — <b><?=number_format((int)$stats['winners'])?></b> winners.</p>
                <?php if($top):?><table class="table table-sm mb-0"><thead><tr><th>Top players</th><th>Points</th><th>Games</th></tr></thead><tbody><?php foreach($top as $t0):?><tr><td><?=e($t0['msisdn'])?></td><td><?=e($t0['pts'])?></td><td><?=e($t0['games'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?><?php endif;?></div></div>
            <div class="col-md-6"><div class="cardx"><h3>Recent games</h3><?php if(!$plays):?><p class="text-muted mb-0">Nothing yet.</p><?php else:?><div class="table-scroll" style="max-height:260px;overflow-y:auto"><table class="table table-sm mb-0"><tbody><?php foreach($plays as $p0):?><tr><td class="text-nowrap small"><?=e($p0['created_at'])?></td><td><?=e($p0['msisdn'])?></td><td><?=e($p0['score'])?>/<?=e($p0['total'])?></td><td><?=$p0['won']?'<span class="badge bg-success">won</span>':''?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div></div>
        <?php else:?><div class="cardx"><p class="text-muted mb-0">Create a quiz on the left, then add questions here.</p></div><?php endif;?>
        </div>
    </div>
    <?php layout_end(); exit;
