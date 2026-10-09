<?php
declare(strict_types=1);
// Page: ?page=users — included by index.php inside its try/catch, after the sign-in, CSRF and $page checks.

    require_perm('manage_users');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $data=$_POST; $id=!empty($data['id'])?(int)$data['id']:null; $data['username']=trim((string)($data['username']??'')); $data['full_name']=trim((string)($data['full_name']??''));
        $hash=null; if(!empty($data['password'])) $hash=password_hash($data['password'],PASSWORD_DEFAULT);
        $chosen=array_values(array_intersect(allowed_schemas(), (array)($data['schemas']??[])));
        $allowedCsv=(!$chosen || count($chosen)===count(allowed_schemas())) ? null : implode(',',$chosen);
        try {
            if ($chosen && !in_array($data['default_schema_name']??'',$chosen,true)) throw new RuntimeException('The default database must be one of the databases this user can open.');
            if (!$chosen) throw new RuntimeException('Tick at least one database for this user.');
            if (!in_array($data['role']??'',['admin','manager','operator','viewer'],true) || !in_array($data['status']??'',['active','disabled'],true) || !in_array($data['default_schema_name']??'',allowed_schemas(),true)) throw new RuntimeException('Invalid role, status or default schema.');
            if (trim((string)($data['username']??''))==='' || trim((string)($data['full_name']??''))==='') throw new RuntimeException('Full name and username are required.');
            if (!empty($data['password']) && strlen((string)$data['password'])<10) throw new RuntimeException('Password must be at least 10 characters.');
            if (!$id && !$hash) throw new RuntimeException('A password (at least 10 characters) is required for a new user.');
            if ($id && $id===(int)user()['id'] && ($data['status']!=='active' || $data['role']!=='admin')) throw new RuntimeException('You cannot disable or demote your own account — ask another admin.');
            if ($id) {
                if ($hash) {
                    portal_pdo()->prepare('UPDATE portal_users SET full_name=?,username=?,password_hash=?,role=?,status=?,default_schema_name=?,allowed_schemas=? WHERE id=?')
                        ->execute([$data['full_name'],$data['username'],$hash,$data['role'],$data['status'],$data['default_schema_name'],$allowedCsv,$id]);
                } else {
                    portal_pdo()->prepare('UPDATE portal_users SET full_name=?,username=?,role=?,status=?,default_schema_name=?,allowed_schemas=? WHERE id=?')
                        ->execute([$data['full_name'],$data['username'],$data['role'],$data['status'],$data['default_schema_name'],$allowedCsv,$id]);
                }
                audit('update','vas_portal','portal_users',(string)$id,json_encode(['username'=>$data['username'],'role'=>$data['role'],'status'=>$data['status'],'databases'=>$allowedCsv ?? 'all','password_changed'=>(bool)$hash]));
            } else {
                portal_pdo()->prepare('INSERT INTO portal_users(full_name,username,password_hash,role,status,default_schema_name,allowed_schemas) VALUES(?,?,?,?,?,?,?)')
                    ->execute([$data['full_name'],$data['username'],$hash,$data['role'],$data['status'],$data['default_schema_name'],$allowedCsv]);
                audit('insert','vas_portal','portal_users',null,json_encode(['username'=>$data['username'],'databases'=>$allowedCsv ?? 'all']));
            }
            flash('success','User saved.');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        } catch (PDOException $e) {
            flash('danger', str_contains($e->getMessage(),'Duplicate') ? 'That username is already taken.' : 'Could not save user: '.$e->getMessage());
        }
        redirect('?page=users');
    }
    $edit=null; if(isset($_GET['id'])){ $st=portal_pdo()->prepare('SELECT id,full_name,username,role,status,default_schema_name,allowed_schemas FROM portal_users WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch(); }
    layout_start('User & Access Control');
    $users=portal_pdo()->query('SELECT id,full_name,username,role,status,default_schema_name,allowed_schemas,last_login,created_at FROM portal_users ORDER BY id DESC')->fetchAll();
    ?><div class="row g-3"><div class="col-lg-4"><div class="cardx"><h3><?= $edit?'Edit User':'Create User' ?></h3><form method="post" data-confirm="Confirm saving this user?"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>"><label class="form-label">Full name</label><input class="form-control mb-2" name="full_name" value="<?=e($edit['full_name']??'')?>" placeholder="Full name"><label class="form-label">Username</label><input class="form-control mb-2" name="username" value="<?=e($edit['username']??'')?>" placeholder="Username"><label class="form-label">Password <?php if($edit):?><small class="text-muted">(leave blank to keep current)</small><?php endif;?></label><input class="form-control mb-2" name="password" type="password" autocomplete="new-password" placeholder="<?=$edit?'Leave blank to keep current':'At least 10 characters'?>"><label class="form-label">Role</label><select name="role" class="form-select mb-2"><?php foreach(['viewer','operator','manager','admin'] as $r):?><option value="<?=e($r)?>" <?=($edit['role']??'viewer')===$r?'selected':''?>><?=e(ucfirst($r))?></option><?php endforeach;?></select><label class="form-label">Status</label><select name="status" class="form-select mb-2"><?php foreach(['active','disabled'] as $s):?><option value="<?=e($s)?>" <?=($edit['status']??'active')===$s?'selected':''?>><?=e(ucfirst($s))?></option><?php endforeach;?></select><label class="form-label">Default database</label><select name="default_schema_name" class="form-select mb-2"><?php foreach(allowed_schemas() as $s):?><option value="<?=e($s)?>" <?=($edit['default_schema_name']??'HeraTesting')===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select><label class="form-label">Databases this user can open</label><div class="db-access mb-1"><?php $mine=($edit && ($edit['allowed_schemas']??null)!==null && trim((string)$edit['allowed_schemas'])!=='') ? explode(',',$edit['allowed_schemas']) : allowed_schemas(); foreach(allowed_schemas() as $s):?><label class="db-check env-<?=schema_kind($s)?>"><input type="checkbox" name="schemas[]" value="<?=e($s)?>" <?=in_array($s,$mine,true)?'checked':''?>><span class="dot"></span><span><strong><?=e(schema_label($s))?></strong><small><?=e($s)?></small></span></label><?php endforeach;?></div><p class="text-muted small mb-3">Admins can always open every database. Anyone else only sees the ones ticked here.</p><button class="btn btn-primary w-100">Save User</button><?php if($edit):?><a class="btn btn-outline-secondary w-100 mt-2" href="?page=users">Cancel Edit</a><?php endif;?></form></div></div><div class="col-lg-8"><div class="cardx"><h3>Users</h3><div class="table-scroll"><table class="table table-hover"><thead><tr><th>Name</th><th>User</th><th>Role</th><th>Status</th><th>Default</th><th>Databases</th><th>Last Login</th><th></th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><?=e($u['full_name'])?></td><td><?=e($u['username'])?></td><td><span class="badge bg-<?=role_badge($u['role'])?>"><?=e($u['role'])?></span></td><td><span class="badge <?=$u['status']==='active'?'bg-success':'bg-secondary'?>"><?=e($u['status'])?></span></td><td><?=e($u['default_schema_name'])?></td><td class="cell-full"><?php if($u['role']==='admin' || $u['allowed_schemas']===null || trim((string)$u['allowed_schemas'])===''):?><span class="badge bg-light text-dark border">All</span><?php else: foreach(explode(',',$u['allowed_schemas']) as $ds):?><span class="badge bg-light text-dark border me-1"><?=e(schema_label(trim($ds)))?></span><?php endforeach; endif;?></td><td class="text-nowrap"><?=e($u['last_login'] ? substr($u['last_login'],0,16) : 'never')?></td><td><a class="btn btn-sm btn-warning" href="?page=users&id=<?=e($u['id'])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></div></div></div><?php layout_end(); exit;
