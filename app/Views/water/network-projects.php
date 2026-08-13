<section class="page-head">
  <div><p class="eyebrow">Jaringan Distribusi · Manajemen Proyek</p><h2>Proyek Jaringan</h2><p>Setiap proyek memiliki diagram, titik manual, pipa, posisi, dan hasil analisisnya sendiri.</p></div>
  <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#networkProjectModal"><i class="bi bi-folder-plus"></i> Tambah Proyek</button>
</section>

<section class="network-project-grid">
  <?php foreach($projects as $project):?>
    <article class="network-project-card <?=$project['is_default']?'is-default':''?>">
      <div class="network-project-card-head"><span><i class="bi bi-folder2-open"></i></span><div><small><?=e($project['code'])?></small><h3><?=e($project['name'])?></h3></div><?php if($project['is_default']):?><em>Proyek Awal</em><?php endif?></div>
      <p><?=e($project['description']?:'Belum ada keterangan proyek.')?></p>
      <div class="network-project-metrics"><span><b><?=number_format((int)$project['manual_nodes'],0,',','.')?></b>Titik manual</span><span><b><?=number_format((int)$project['links'],0,',','.')?></b>Pipa/link</span><span><b><?=number_format((int)$project['positioned_nodes'],0,',','.')?></b>Posisi titik</span></div>
      <div class="network-project-status"><span class="status-badge status-<?=e($project['status'])?>"><?=e(ucfirst($project['status']))?></span><small>Diperbarui <?=e(date('d/m/Y H:i',strtotime($project['updated_at'])))?></small></div>
      <div class="network-project-actions">
        <a class="btn btn-primary" href="<?=e(url('distribution-networks?project='.$project['id']))?>"><i class="bi bi-diagram-3"></i> Buka Diagram & Analisis</a>
        <button class="btn btn-outline-secondary network-project-edit" type="button" data-project="<?=e(json_encode(['id'=>$project['id'],'code'=>$project['code'],'name'=>$project['name'],'description'=>$project['description'],'status'=>$project['status']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>"><i class="bi bi-pencil"></i></button>
        <?php if(!$project['is_default']&&has_role(['super_admin','administrator'])):?><form method="post" action="<?=url('network-projects')?>" onsubmit="return confirm('Arsipkan proyek ini? Data diagram tidak dihapus permanen.')"><?=csrf_field()?><input type="hidden" name="project_id" value="<?=$project['id']?>"><input type="hidden" name="_method" value="DELETE"><button class="btn btn-outline-danger"><i class="bi bi-archive"></i></button></form><?php endif?>
      </div>
    </article>
  <?php endforeach?>
</section>

<div class="modal fade" id="networkProjectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><form class="modal-content" method="post" action="<?=url('network-projects')?>" id="networkProjectForm">
    <div class="modal-header"><div><p class="eyebrow mb-1">Data Proyek</p><h3 class="modal-title h5" id="networkProjectModalTitle">Tambah Proyek Jaringan</h3></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><?=csrf_field()?><input type="hidden" name="project_id" id="networkProjectId"><div class="row g-3">
      <div class="col-md-5"><label class="form-label">Kode Proyek <span class="required-mark">*</span></label><input class="form-control" name="code" id="networkProjectCode" required placeholder="PRJ-002"></div>
      <div class="col-md-7"><label class="form-label">Nama Proyek <span class="required-mark">*</span></label><input class="form-control" name="name" id="networkProjectName" required></div>
      <div class="col-12"><label class="form-label">Status</label><select class="form-select" name="status" id="networkProjectStatus"><option value="aktif">Aktif</option><option value="draft">Draft</option></select></div>
      <div class="col-12"><label class="form-label">Keterangan</label><textarea class="form-control" name="description" id="networkProjectDescription" rows="3"></textarea></div>
    </div></div>
    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary"><i class="bi bi-check2"></i> Simpan Proyek</button></div>
  </form></div>
</div>
