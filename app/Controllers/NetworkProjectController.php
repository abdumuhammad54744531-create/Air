<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use PDOException;

final class NetworkProjectController
{
    public function handle(string $method): void
    {
        require_auth(['super_admin','administrator','operator']);
        if ($method==='POST') {$this->store();return;}
        $this->index();
    }

    private function index(): void
    {
        $projects=Database::query(
            "SELECT p.*,
              (SELECT COUNT(*) FROM distribution_nodes n WHERE n.project_id=p.id AND n.deleted_at IS NULL) manual_nodes,
              (SELECT COUNT(*) FROM distribution_networks l WHERE l.project_id=p.id AND l.deleted_at IS NULL) links,
              (SELECT COUNT(*) FROM distribution_node_positions x WHERE x.project_id=p.id) positioned_nodes
             FROM network_projects p WHERE p.deleted_at IS NULL ORDER BY p.is_default DESC,p.updated_at DESC"
        )->fetchAll();
        view('water/network-projects',['title'=>'Proyek Jaringan Distribusi','projects'=>$projects]);
    }

    private function store(): void
    {
        verify_csrf();
        $id=(int)($_POST['project_id']??0);
        $method=(string)($_POST['_method']??'');
        if ($method==='DELETE') {
            if (!has_role(['super_admin','administrator'])) {flash('danger','Anda tidak mempunyai izin mengarsipkan proyek.');redirect('network-projects');}
            $project=Database::query("SELECT * FROM network_projects WHERE id=? AND deleted_at IS NULL",[$id])->fetch();
            if (!$project || (int)$project['is_default']===1) {flash('danger','Proyek utama tidak dapat diarsipkan.');redirect('network-projects');}
            Database::query("UPDATE network_projects SET status='arsip',deleted_at=NOW(),updated_at=NOW() WHERE id=?",[$id]);
            activity('hapus','network-projects',$id,$project,null);
            flash('success','Proyek jaringan telah diarsipkan. Data diagram tetap tersimpan.');
            redirect('network-projects');
        }
        $code=strtoupper(trim((string)($_POST['code']??'')));
        $name=trim((string)($_POST['name']??''));
        $description=trim((string)($_POST['description']??''))?:null;
        $status=in_array(($_POST['status']??''),['draft','aktif'],true)?$_POST['status']:'aktif';
        if ($code===''||$name==='') {flash('danger','Kode dan nama proyek wajib diisi.');redirect('network-projects');}
        try {
            if ($id) {
                $before=Database::query("SELECT * FROM network_projects WHERE id=? AND deleted_at IS NULL",[$id])->fetch();
                if (!$before) throw new \RuntimeException('Proyek tidak ditemukan.');
                Database::query("UPDATE network_projects SET code=?,name=?,description=?,status=?,updated_at=NOW() WHERE id=?",[$code,$name,$description,$status,$id]);
                activity('edit','network-projects',$id,$before,compact('code','name','description','status'));
                flash('success','Data proyek berhasil diperbarui.');
            } else {
                Database::query("INSERT INTO network_projects(code,name,description,status,is_default,created_by,created_at,updated_at) VALUES(?,?,?,?,0,?,NOW(),NOW())",[$code,$name,$description,$status,user()['id']]);
                $id=(int)Database::connection()->lastInsertId();
                activity('tambah','network-projects',$id,null,compact('code','name','description','status'));
                $_SESSION['network_project_id']=$id;
                flash('success','Proyek baru dibuat. Diagram proyek masih kosong dan siap digambar.');
            }
        } catch (PDOException $error) {
            flash('danger',str_contains($error->getMessage(),'Duplicate')?'Kode proyek sudah digunakan.':'Proyek tidak dapat disimpan.');
        } catch (\Throwable $error) {
            flash('danger',$error->getMessage());
        }
        redirect('network-projects');
    }
}
