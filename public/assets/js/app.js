document.addEventListener('DOMContentLoaded',()=>{
  const sidebar=document.querySelector('#sidebar');
  document.querySelector('#sidebarToggle')?.addEventListener('click',()=>sidebar?.classList.toggle('open'));
  const sidebarNav=document.querySelector('.sidebar-nav'),sidebarScrollKey='simma-sidebar-scroll-top';
  if(sidebarNav){
    const savedScroll=Number(sessionStorage.getItem(sidebarScrollKey));
    const restoreSidebarScroll=()=>{if(Number.isFinite(savedScroll)&&savedScroll>0)sidebarNav.scrollTop=savedScroll};
    requestAnimationFrame(restoreSidebarScroll);
    window.addEventListener('load',()=>setTimeout(restoreSidebarScroll,80),{once:true});
    sidebarNav.addEventListener('scroll',()=>sessionStorage.setItem(sidebarScrollKey,String(sidebarNav.scrollTop)),{passive:true});
    sidebarNav.querySelectorAll('a[href]').forEach(link=>link.addEventListener('click',()=>sessionStorage.setItem(sidebarScrollKey,String(sidebarNav.scrollTop))));
  }
  document.querySelectorAll('[data-toggle-password]').forEach(btn=>btn.addEventListener('click',()=>{
    const input=document.querySelector(btn.dataset.togglePassword); if(!input)return;
    input.type=input.type==='password'?'text':'password';
    btn.querySelector('i').className=input.type==='password'?'bi bi-eye':'bi bi-eye-slash';
  }));
  const mainCanvas=document.querySelector('#mainChart');
  if(mainCanvas&&window.Chart){
    const rows=JSON.parse(mainCanvas.dataset.chart||'[]');
    const select=document.querySelector('#chartParameter'); let chart;
    const draw=()=>{
      const filtered=rows.filter(r=>r.parameter===select.value);
      chart?.destroy(); chart=new Chart(mainCanvas,{type:'line',data:{labels:filtered.map(r=>r.label),datasets:[{label:(select.options[select.selectedIndex]?.text||select.value)+' '+(filtered[0]?.unit||''),data:filtered.map(r=>r.value),borderColor:'#1877d2',backgroundColor:'#1877d21a',fill:true,tension:.35,pointBackgroundColor:'#fff',pointBorderWidth:2,pointRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{grid:{color:'#eaf0f4'}}}}});
    }; select?.addEventListener('change',draw); draw();
    if(window.dashboardRefresh) setInterval(async()=>{try{const res=await fetch('dashboard/data');const json=await res.json();if(!json.success)return;Object.entries(json.stats).forEach(([k,v])=>{const el=document.querySelector(`[data-stat="${k}"]`);if(el)el.textContent=new Intl.NumberFormat('id-ID').format(v)})}catch(e){}},window.dashboardRefresh*1000);
  }
  const statusCanvas=document.querySelector('#statusChart');
  if(statusCanvas&&window.Chart)new Chart(statusCanvas,{type:'doughnut',data:{labels:['Aktif','Offline','Perawatan'],datasets:[{data:[+statusCanvas.dataset.active,+statusCanvas.dataset.offline,+statusCanvas.dataset.maintenance],backgroundColor:['#0fa56f','#e34859','#f1a72c'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'72%',plugins:{legend:{display:false}}}});
  const portalChart=document.querySelector('#portalDebitChart');
  if(portalChart&&window.Chart){
    const rows=JSON.parse(portalChart.dataset.trend||'[]'),demand=+portalChart.dataset.demand;
    new Chart(portalChart,{type:'line',data:{labels:rows.map(r=>r.label?new Date(r.label.replace(' ','T')).toLocaleString('id-ID',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}):new Date(r.reading_date+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'2-digit'})),datasets:[
      {label:'Debit (L/s)',data:rows.map(r=>+r.value),borderColor:'#087f4d',backgroundColor:'#087f4d',pointRadius:4,pointBorderWidth:2,pointBorderColor:'#fff',tension:.2},
      {label:'Kebutuhan Jam Puncak',data:rows.map(()=>demand),borderColor:'#ef2020',borderDash:[7,5],pointRadius:0,borderWidth:2}
    ]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{color:'#0a1833'}},y:{suggestedMin:0,grid:{color:'#dce5ef'},ticks:{color:'#0a1833'}}}}});
  }
  const mapElement=document.querySelector('#monitoringMap');
  if(mapElement&&window.L){
    const data=JSON.parse(mapElement.dataset.locations||'[]'),map=L.map(mapElement,{scrollWheelZoom:true,wheelPxPerZoomLevel:60}).setView([-4.0,122.5],9);
    addMapBaseLayers(map);
    const markers=[];
    data.forEach(item=>{const color=item.device_status==='aktif'?'#0fa56f':item.device_status==='dalam_perawatan'?'#2284d6':'#e34859';
      const icon=L.divIcon({className:'',html:`<div style="width:18px;height:18px;background:${color};border:3px solid white;border-radius:50%;box-shadow:0 2px 8px #2346"></div>`,iconSize:[18,18]});
      const marker=L.marker([+item.latitude,+item.longitude],{icon}).addTo(map).bindPopup(`<strong>${escapeHtml(item.name)}</strong><br>${escapeHtml(item.device_name||'Belum ada alat')}<br><span>${escapeHtml(item.device_status||'tidak diketahui')}</span>`); marker.meta=item;markers.push(marker);
    });
    if(markers.length)map.fitBounds(L.featureGroup(markers).getBounds().pad(.2));
    const filter=()=>{const q=(document.querySelector('#mapSearch')?.value||'').toLowerCase(),status=document.querySelector('#mapStatus')?.value||'';markers.forEach(m=>{const show=(!q||m.meta.name.toLowerCase().includes(q))&&(!status||m.meta.device_status===status);show?m.addTo(map):m.remove()})};
    document.querySelector('#mapSearch')?.addEventListener('input',filter);document.querySelector('#mapStatus')?.addEventListener('change',filter);
  }
  const publicMapElement=document.querySelector('#publicLocationsMap');
  if(publicMapElement&&window.L){
    const locations=JSON.parse(publicMapElement.dataset.locations||'[]').filter(item=>item.latitude&&item.longitude);
    const selected=+publicMapElement.dataset.selected,map=L.map(publicMapElement,{scrollWheelZoom:true,wheelPxPerZoomLevel:60}).setView([-4,122.5],9),markers=[];
    addMapBaseLayers(map);
    locations.forEach(item=>{
      const isActive=+item.id===selected,color=isActive?'#f59e0b':'#087f5b',size=isActive?24:19;
      const icon=L.divIcon({className:'portal-map-marker',html:`<div class="portal-map-marker-dot" style="width:${size}px;height:${size}px;background:${color}"></div><span class="portal-map-marker-label ${isActive?'active':''}">${escapeHtml(item.name)}</span>`,iconSize:[size,size],iconAnchor:[size/2,size/2]});
      const href=`${publicMapElement.dataset.baseUrl}?location=${encodeURIComponent(item.id)}`;
      const region=[item.village,item.district,item.city,item.province].filter(Boolean).map(escapeHtml).join(', ');
      const updated=item.last_update?new Date(item.last_update.replace(' ','T')).toLocaleString('id-ID'):'Belum ada data';
      const photoUrls=[...new Set((Array.isArray(item.photos)?item.photos:(item.photo?[item.photo]:[])).map(photo=>encodeURI(String(photo)).replaceAll('"','%22')).filter(Boolean))];
      const photosData=encodeURIComponent(JSON.stringify(photoUrls));
      const photo=photoUrls.length?`<div class="public-photo-gallery"><button type="button" class="public-photo-arrow" data-photo-gallery="previous" data-photos="${photosData}" aria-label="Foto sebelumnya"><i class="bi bi-chevron-left"></i></button><button type="button" class="public-location-photo" data-location-photo="${photoUrls[0]}" data-photos="${photosData}" data-photo-index="0" aria-label="Perbesar foto dokumentasi ${escapeHtml(item.name)}"><img src="${photoUrls[0]}" alt="Dokumentasi ${escapeHtml(item.name)}"><span><i class="bi bi-arrows-fullscreen"></i> Foto 1 dari ${photoUrls.length} · Perbesar</span></button><button type="button" class="public-photo-arrow" data-photo-gallery="next" data-photos="${photosData}" aria-label="Foto berikutnya"><i class="bi bi-chevron-right"></i></button></div>`:'';
      const details=`<div class="public-map-popup"><h3>${escapeHtml(item.name)}</h3><span class="popup-code">${escapeHtml(item.code)} · ${escapeHtml(item.type)}</span>
        <dl><dt>Wilayah</dt><dd>${region||'—'}</dd><dt>Alamat</dt><dd>${escapeHtml(item.address||'—')}</dd><dt>Koordinat</dt><dd>${escapeHtml(item.latitude)}, ${escapeHtml(item.longitude)}</dd>
        <dt>Elevasi</dt><dd>${item.elevation?escapeHtml(item.elevation)+' meter':'—'}</dd><dt>Perangkat</dt><dd>${item.device_count} alat (${item.online_devices||0} online)</dd>
        <dt>Nama alat</dt><dd>${escapeHtml(item.device_names||'Belum ada alat publik')}</dd><dt>Pembaruan</dt><dd>${escapeHtml(updated)}</dd></dl>
        ${item.description?`<p>${escapeHtml(item.description)}</p>`:''}${photo}<a href="${href}">Tampilkan data lokasi ini</a></div>`;
      const marker=L.marker([+item.latitude,+item.longitude],{icon,zIndexOffset:isActive?1000:0}).addTo(map)
        .bindPopup(details,{maxWidth:380,minWidth:280});
      marker.on('click',()=>{if(isActive)marker.openPopup()});markers.push(marker);
      if(isActive)marker.openPopup();
    });
    if(markers.length)map.fitBounds(L.featureGroup(markers).getBounds().pad(.25),{maxZoom:13});
    const fullscreenButton=document.querySelector('#publicMapFullscreen');
    fullscreenButton?.addEventListener('click',async()=>{
      try{
        if(document.fullscreenElement===publicMapElement) await document.exitFullscreen();
        else await publicMapElement.requestFullscreen();
      }catch(error){}
    });
    document.addEventListener('fullscreenchange',()=>{
      const active=document.fullscreenElement===publicMapElement;
      if(fullscreenButton)fullscreenButton.innerHTML=active?'<i class="bi bi-fullscreen-exit"></i> Keluar layar penuh':'<i class="bi bi-arrows-fullscreen"></i> Layar penuh';
      setTimeout(()=>map.invalidateSize(),100);
    });
    setInterval(async()=>{
      try{
        const response=await fetch(publicMapElement.dataset.syncUrl,{cache:'no-store',headers:{Accept:'application/json'}});
        const payload=await response.json(); if(!payload.success)return;
        const signature=payload.data.map(item=>[item.code,item.latitude,item.longitude,item.updated_at].join(':')).join('|');
        if(signature!==publicMapElement.dataset.signature)window.location.reload();
      }catch(error){}
    },30000);
  }
  document.addEventListener('click',event=>{
    const arrow=event.target.closest('[data-photo-gallery]');
    if(arrow){
      event.preventDefault();event.stopPropagation();
      try{
        const photos=JSON.parse(decodeURIComponent(arrow.dataset.photos||''));if(!photos.length)return;
        const gallery=arrow.closest('.public-photo-gallery'),photoButton=gallery?.querySelector('[data-location-photo]');if(!photoButton)return;
        const current=Number(photoButton.dataset.photoIndex||0),next=(current+(arrow.dataset.photoGallery==='next'?1:-1)+photos.length)%photos.length;
        photoButton.dataset.photoIndex=String(next);photoButton.dataset.locationPhoto=photos[next];
        const image=photoButton.querySelector('img'),caption=photoButton.querySelector('span');if(image)image.src=photos[next];if(caption)caption.innerHTML=`<i class="bi bi-arrows-fullscreen"></i> Foto ${next+1} dari ${photos.length} · Perbesar`;
      }catch(error){}return;
    }
    const button=event.target.closest('[data-location-photo]');if(!button)return;
    const photo=button.dataset.locationPhoto;if(!photo)return;
    let photos=[photo],index=Number(button.dataset.photoIndex||0);
    try{const parsed=JSON.parse(decodeURIComponent(button.dataset.photos||''));if(Array.isArray(parsed)&&parsed.length){photos=parsed;index=Math.max(0,Math.min(index,photos.length-1));}}catch(error){}
    const overlay=document.createElement('div');overlay.className='public-photo-lightbox';
    overlay.innerHTML=`<button type="button" class="public-photo-close" aria-label="Tutup foto"><i class="bi bi-x-lg"></i></button><button type="button" class="public-lightbox-arrow previous" aria-label="Foto sebelumnya"><i class="bi bi-chevron-left"></i></button><figure><img src="${photos[index]}" alt="Foto dokumentasi lokasi"><figcaption>Foto ${index+1} dari ${photos.length}</figcaption></figure><button type="button" class="public-lightbox-arrow next" aria-label="Foto berikutnya"><i class="bi bi-chevron-right"></i></button>`;
    const image=overlay.querySelector('img'),caption=overlay.querySelector('figcaption');
    const show=indexValue=>{index=(indexValue+photos.length)%photos.length;if(image)image.src=photos[index];if(caption)caption.textContent=`Foto ${index+1} dari ${photos.length}`};
    const close=()=>{if(document.fullscreenElement===overlay)document.exitFullscreen?.().catch(()=>{});overlay.remove();document.removeEventListener('keydown',keyboard)};
    const keyboard=e=>{if(e.key==='Escape')close();if(e.key==='ArrowLeft')show(index-1);if(e.key==='ArrowRight')show(index+1)};
    overlay.addEventListener('click',e=>{if(e.target===overlay||e.target.closest('.public-photo-close'))close();else if(e.target.closest('.previous'))show(index-1);else if(e.target.closest('.next'))show(index+1)});
    document.body.append(overlay);
    document.addEventListener('keydown',keyboard);
    overlay.requestFullscreen?.().catch(()=>{});
    document.addEventListener('fullscreenchange',()=>{if(!document.fullscreenElement&&document.body.contains(overlay)){overlay.remove();document.removeEventListener('keydown',keyboard)}},{once:true});
  });
  document.querySelectorAll('[data-export-table]').forEach(btn=>btn.addEventListener('click',()=>{
    const table=document.querySelector(btn.dataset.exportTable);if(!table)return;
    const csv=[...table.rows].map(row=>[...row.cells].slice(0,-1).map(cell=>`"${cell.innerText.trim().replaceAll('"','""')}"`).join(',')).join('\n');
    const blob=new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8'}),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='ekspor-data-'+new Date().toISOString().slice(0,10)+'.csv';a.click();URL.revokeObjectURL(a.href);
  }));
  const networkProjectForm=document.querySelector('#networkProjectForm'),networkProjectModalElement=document.querySelector('#networkProjectModal');
  if(networkProjectForm&&networkProjectModalElement){
    const projectModal=bootstrap.Modal.getOrCreateInstance(networkProjectModalElement);
    document.querySelectorAll('.network-project-edit').forEach(button=>button.addEventListener('click',()=>{
      const project=JSON.parse(button.dataset.project||'{}');networkProjectForm.reset();
      document.querySelector('#networkProjectId').value=project.id||'';document.querySelector('#networkProjectCode').value=project.code||'';document.querySelector('#networkProjectName').value=project.name||'';document.querySelector('#networkProjectDescription').value=project.description||'';document.querySelector('#networkProjectStatus').value=project.status||'aktif';document.querySelector('#networkProjectModalTitle').textContent='Ubah Proyek Jaringan';projectModal.show();
    }));
    networkProjectModalElement.addEventListener('hidden.bs.modal',()=>{networkProjectForm.reset();document.querySelector('#networkProjectId').value='';document.querySelector('#networkProjectModalTitle').textContent='Tambah Proyek Jaringan'});
  }
  const bulkRoughnessFormula=document.querySelector('#bulkRoughnessFormula');
  document.querySelectorAll('.bulk-wide-table').forEach(table=>{
    table.addEventListener('paste',event=>{
      const target=event.target.closest('input,select,textarea'),text=event.clipboardData?.getData('text/plain')||'';
      if(!target||(!text.includes('\t')&&!text.includes('\n'))||(target.tagName==='TEXTAREA'&&!text.includes('\t')))return;
      const startCell=target.closest('td'),startRow=target.closest('tr');
      if(!startCell||!startRow)return;
      event.preventDefault();
      const tableRows=[...table.tBodies[0].rows],rowStart=tableRows.indexOf(startRow),columnStart=startCell.cellIndex;
      const pastedRows=text.replace(/\r/g,'').split('\n');if(pastedRows.at(-1)==='')pastedRows.pop();
      pastedRows.forEach((line,rowOffset)=>{
        const destinationRow=tableRows[rowStart+rowOffset];if(!destinationRow)return;
        line.split('\t').forEach((rawValue,columnOffset)=>{
          const cell=destinationRow.cells[columnStart+columnOffset],control=cell?.querySelector('input,select,textarea');
          if(!control||control.readOnly||control.disabled)return;
          const value=rawValue.trim();
          if(control.tagName==='SELECT'){
            const normalized=value.toLowerCase();
            const option=[...control.options].find(item=>item.value.toLowerCase()===normalized||item.textContent.trim().toLowerCase()===normalized);
            if(option)control.value=option.value;
          }else control.value=control.type==='number'?value.replace(',','.'):rawValue;
          control.dispatchEvent(new Event('input',{bubbles:true}));control.dispatchEvent(new Event('change',{bubbles:true}));
          cell.classList.add('bulk-cell-pasted');setTimeout(()=>cell.classList.remove('bulk-cell-pasted'),1200);
        });
      });
    });
  });
  if(bulkRoughnessFormula){
    const standards={HDPE:{'H-W':150,'D-W':.0015,'C-M':.009},PVC:{'H-W':150,'D-W':.0015,'C-M':.009},Galvanis:{'H-W':120,'D-W':.15,'C-M':.016},Baja:{'H-W':130,'D-W':.045,'C-M':.012},Beton:{'H-W':130,'D-W':.3,'C-M':.013}};
    const descriptions={'H-W':'Hazen–Williams memakai faktor C.','D-W':'Darcy–Weisbach memakai kekasaran absolut dalam mm.','C-M':'Chezy–Manning memakai koefisien Manning n.'};
    const syncBulkRoughness=()=>{
      const formula=bulkRoughnessFormula.value;
      document.querySelector('#bulkRoughnessUnit').textContent=descriptions[formula];
      document.querySelectorAll('#bulkRouteTable tbody tr').forEach(route=>{
        const material=route.querySelector('[data-bulk-material]'),roughness=route.querySelector('[data-bulk-roughness]'),automatic=standards[material?.value]?.[formula];
        if(!roughness)return;
        roughness.readOnly=automatic!==undefined;roughness.classList.toggle('bg-light',automatic!==undefined);
        if(automatic!==undefined)roughness.value=automatic;
        roughness.title=automatic!==undefined?'Nilai standar otomatis. Pilih material Lainnya untuk mengisi manual.':'Nilai manual untuk material Lainnya.';
      });
    };
    bulkRoughnessFormula.addEventListener('change',syncBulkRoughness);
    document.querySelectorAll('[data-bulk-material]').forEach(field=>field.addEventListener('change',syncBulkRoughness));
    syncBulkRoughness();
  }
  const wizard=document.querySelector('#simulationWizard');
  if(wizard){
    let step=1;const pages=[...wizard.querySelectorAll('.wizard-page')],tabs=[...wizard.querySelectorAll('[data-wizard-step]')],prev=wizard.querySelector('#wizardPrev'),next=wizard.querySelector('#wizardNext'),run=wizard.querySelector('#wizardRun'),current=wizard.querySelector('#wizardCurrent');
    const show=value=>{step=Math.max(1,Math.min(6,value));pages.forEach(p=>p.classList.toggle('active',+p.dataset.page===step));tabs.forEach(t=>{t.classList.toggle('active',+t.dataset.wizardStep===step);t.classList.toggle('done',+t.dataset.wizardStep<step)});prev.disabled=step===1;next.classList.toggle('d-none',step===6);run.classList.toggle('d-none',step!==6);current.textContent=step;wizard.scrollIntoView({behavior:'smooth',block:'start'})};
    const valid=()=>{const page=pages[step-1];for(const input of page.querySelectorAll('[required]')){if(!input.checkValidity()){input.reportValidity();return false}}if(step===2&&!wizard.querySelector('.source-check:checked')){alert('Pilih minimal satu sumber air.');return false}if(step===5&&!wizard.querySelector('input[name="area_ids[]"]:checked')){alert('Pilih minimal satu wilayah layanan.');return false}return true};
    next.addEventListener('click',()=>{if(valid())show(step+1)});prev.addEventListener('click',()=>show(step-1));tabs.forEach(t=>t.addEventListener('click',()=>{const target=+t.dataset.wizardStep;if(target<step||valid())show(target)}));
    const flowMode=wizard.querySelector('#flowMode'),toggleManual=()=>wizard.querySelectorAll('.manual-flow').forEach(input=>input.disabled=flowMode.value!=='manual');flowMode.addEventListener('change',toggleManual);toggleManual();
  }
  const balanceChart=document.querySelector('#waterBalanceChart');
  if(balanceChart&&window.Chart)new Chart(balanceChart,{type:'bar',data:{labels:['Debit Efektif','Kebutuhan'],datasets:[{data:[+balanceChart.dataset.available,+balanceChart.dataset.demand],backgroundColor:['#0fa56f','#2563eb'],borderRadius:8}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,title:{display:true,text:'Liter/detik'}},x:{grid:{display:false}}}}});
  const reservoirChart=document.querySelector('#reservoirChart');
  if(reservoirChart&&window.Chart){const steps=JSON.parse(reservoirChart.dataset.steps||'[]');new Chart(reservoirChart,{type:'line',data:{labels:steps.map(s=>s.step_number),datasets:[{label:'Volume akhir (m³)',data:steps.map(s=>+s.reservoir_final_m3),borderColor:'#2563eb',backgroundColor:'#2563eb20',fill:true,tension:.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true},x:{grid:{display:false}}}}})}
  const networkBoard=document.querySelector('#distributionNetworkBoard');
  if(networkBoard){
    const layerToggle=document.querySelector('#networkLayerToggle'),layerOptions=document.querySelector('#networkLayerOptions');
    layerToggle?.addEventListener('click',()=>{const open=layerToggle.getAttribute('aria-expanded')!=='true';layerToggle.setAttribute('aria-expanded',String(open));layerOptions.hidden=!open});
    const nodes=JSON.parse(networkBoard.dataset.nodes||'[]'),routes=JSON.parse(networkBoard.dataset.routes||'[]');
    const nodeByKey=Object.fromEntries(nodes.map(node=>[node.key,node])),routeById=Object.fromEntries(routes.map(route=>[String(route.id),route]));
    const svg=document.querySelector('#distributionNetworkLines'),inspector=document.querySelector('#networkInspector'),hint=document.querySelector('#networkSelectionHint');
    const modalElement=document.querySelector('#networkRouteModal'),modal=bootstrap.Modal.getOrCreateInstance(modalElement),form=document.querySelector('#networkRouteForm');
    const nodeModalElement=document.querySelector('#networkNodeModal'),nodeModal=bootstrap.Modal.getOrCreateInstance(nodeModalElement),nodeForm=document.querySelector('#networkNodeForm');
    const originSelect=document.querySelector('#networkOriginKey'),destinationSelect=document.querySelector('#networkDestinationKey');
    const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
    const projectId=String(networkBoard.dataset.projectId||'0'),projectStorageKey=key=>`${key}-${projectId}`;
    document.querySelector('#networkProjectSwitcher')?.addEventListener('change',event=>{window.location.href=`${networkBoard.dataset.deleteRouteUrl}?project=${encodeURIComponent(event.target.value)}`});
    const hydraulicModalElement=document.querySelector('#networkHydraulicModal'),hydraulicForm=document.querySelector('#networkHydraulicForm'),hydraulicResult=document.querySelector('#networkHydraulicResult');
    const hydraulicModal=hydraulicModalElement?bootstrap.Modal.getOrCreateInstance(hydraulicModalElement):null;
    const headlossFormula=document.querySelector('#networkHeadlossFormula'),headlossFormulaKey=projectStorageKey('simma-network-headloss-formula');
    const hydraulicAnalysisType=document.querySelector('#networkAnalysisType'),hydraulicPatternPanel=document.querySelector('#networkDemandPatternPanel'),applyGlobalPattern=document.querySelector('#networkApplyGlobalPattern'),hourlyPatternInputs=[...document.querySelectorAll('[data-hydraulic-hour]')],patternCanvas=document.querySelector('#networkDemandPatternChart');
    let demandPatternChart=null;
    const updateDemandPatternChart=()=>{
      const values=hourlyPatternInputs.map(input=>Math.max(0,+input.value||0));
      if(demandPatternChart){demandPatternChart.data.datasets[0].data=values;demandPatternChart.update('none');return}
      if(patternCanvas&&window.Chart)demandPatternChart=new Chart(patternCanvas,{
        type:'line',
        data:{labels:hourlyPatternInputs.map((input,index)=>`${String(index).padStart(2,'0')}:00`),datasets:[{label:'Faktor kebutuhan',data:values,borderColor:'#087f5b',backgroundColor:'#087f5b20',fill:true,tension:.32,pointRadius:2,pointHoverRadius:5}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{maxTicksLimit:8}},y:{beginAtZero:true,title:{display:true,text:'Faktor × base demand'}}}}
      });
    };
    const updateHydraulicPatternState=()=>{const extended=hydraulicAnalysisType?.value==='EXTENDED';hydraulicPatternPanel?.classList.toggle('is-disabled',!extended);if(applyGlobalPattern)applyGlobalPattern.disabled=!extended;hourlyPatternInputs.forEach(input=>input.disabled=!extended||!applyGlobalPattern?.checked)};
    hourlyPatternInputs.forEach(input=>input.addEventListener('input',updateDemandPatternChart));hydraulicAnalysisType?.addEventListener('change',updateHydraulicPatternState);applyGlobalPattern?.addEventListener('change',updateHydraulicPatternState);hydraulicModalElement?.addEventListener('shown.bs.modal',()=>{updateDemandPatternChart();demandPatternChart?.resize()});updateHydraulicPatternState();
    document.querySelector('#networkOpenPattern')?.addEventListener('click',()=>{if(hydraulicAnalysisType)hydraulicAnalysisType.value='EXTENDED';updateHydraulicPatternState();hydraulicModal?.show();setTimeout(()=>hydraulicPatternPanel?.scrollIntoView({behavior:'smooth',block:'start'}),260)});
    if(headlossFormula){
      const savedFormula=localStorage.getItem(headlossFormulaKey);
      if(['H-W','D-W','C-M'].includes(savedFormula))headlossFormula.value=savedFormula;
    }
    let hydraulicOutputResults=null,refreshHydraulicOutput=()=>{};
    try{hydraulicOutputResults=JSON.parse(localStorage.getItem(projectStorageKey('simma-network-hydraulic-results'))||'null')}catch(error){}
    const hydraulicSummaryLabels={junctions:'Junction',reservoirs:'Reservoir/Sumber',tanks:'Tank',pipes:'Pipa',pumps:'Pompa',valves:'Valve',patterns:'Pattern',curves:'Kurva'};
    const renderHydraulicResult=payload=>{
      const validation=payload.validation,summary=payload.payload_summary||{};
      if(!validation){
        hydraulicResult.innerHTML=`<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill"></i> ${escapeHtml(payload.message||'Respons analisis tidak valid.')}</div>`;
        return;
      }
      const state=validation.valid?'valid':'invalid',stateIcon=validation.valid?'bi-check-circle-fill':'bi-exclamation-octagon-fill';
      const summaryHtml=Object.entries(summary).map(([key,value])=>`<span><b>${Number(value)||0}</b>${escapeHtml(hydraulicSummaryLabels[key]||key)}</span>`).join('');
      const rows=(validation.items||[]).map(item=>`<tr class="hydraulic-${item.severity}"><td><span class="hydraulic-severity">${item.severity==='error'?'Error':item.severity==='warning'?'Peringatan':'Info'}</span></td><td>${escapeHtml(item.object||'Jaringan')}</td><td>${escapeHtml(item.field||'—')}</td><td>${escapeHtml(item.message||'')}</td></tr>`).join('');
      const engine=payload.engine;
      if(engine?.success&&engine.results?.available){
        hydraulicOutputResults=engine.results;localStorage.setItem(projectStorageKey('simma-network-hydraulic-results'),JSON.stringify(engine.results));refreshHydraulicOutput();
      }
      hydraulicResult.innerHTML=`
        <div class="hydraulic-state ${state}"><i class="bi ${stateIcon}"></i><div><strong>${validation.valid?'Model jaringan valid':'Model belum dapat dijalankan'}</strong><span>${validation.errors} error · ${validation.warnings} peringatan · ${validation.counts.nodes} titik · ${validation.counts.links} link</span></div></div>
        <div class="hydraulic-payload-summary">${summaryHtml}</div>
        ${rows?`<div class="table-responsive"><table class="table table-sm hydraulic-validation-table"><thead><tr><th>Status</th><th>Objek</th><th>Kolom</th><th>Pemeriksaan</th></tr></thead><tbody>${rows}</tbody></table></div>`:''}
        ${engine?`<div class="hydraulic-engine ${engine.success?'success':'failed'}"><h4><i class="bi ${engine.success?'bi-cpu-fill':'bi-x-octagon-fill'}"></i> ${escapeHtml(payload.message||'Hasil EPANET')}</h4>${engine.engine_errors?.length?`<ul>${engine.engine_errors.map(error=>`<li>${escapeHtml(error)}</li>`).join('')}</ul>`:''}${engine.report_excerpt?`<details><summary>Ringkasan laporan mesin</summary><pre>${escapeHtml(engine.report_excerpt)}</pre></details>`:''}</div>`:''}`;
    };
    const requestHydraulic=async action=>{
      if(!hydraulicForm?.checkValidity()){hydraulicForm?.reportValidity();return}
      const isRun=action==='run',url=isRun?networkBoard.dataset.hydraulicRunUrl:networkBoard.dataset.hydraulicValidateUrl;
      const buttons=[document.querySelector('#networkHydraulicValidateInModal'),document.querySelector('#networkHydraulicRunSubmit')].filter(Boolean);
      buttons.forEach(button=>button.disabled=true);
      hydraulicResult.innerHTML='<div class="hydraulic-loading"><span class="spinner-border spinner-border-sm"></span><strong>'+(isRun?'EPANET sedang menghitung jaringan...':'Memeriksa kelengkapan dan hubungan jaringan...')+'</strong></div>';
      try{
        const body=new URLSearchParams(new FormData(hydraulicForm));body.set('_token',csrf);body.set('project_id',projectId);
        const response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});
        const payload=await response.json();renderHydraulicResult(payload);
      }catch(error){
        renderHydraulicResult({message:'Layanan analisis tidak dapat dihubungi. Muat ulang halaman lalu coba kembali.'});
      }finally{buttons.forEach(button=>button.disabled=false)}
    };
    document.querySelector('#networkValidateHydraulic')?.addEventListener('click',()=>{hydraulicModal?.show();setTimeout(()=>requestHydraulic('validate'),180)});
    document.querySelector('#networkRunHydraulic')?.addEventListener('click',()=>hydraulicModal?.show());
    document.querySelector('#networkHydraulicValidateInModal')?.addEventListener('click',()=>requestHydraulic('validate'));
    hydraulicForm?.addEventListener('submit',event=>{event.preventDefault();requestHydraulic('run')});
    const analysisModeElement=document.querySelector('#networkAnalysisModeModal'),analysisModeModal=analysisModeElement?bootstrap.Modal.getOrCreateInstance(analysisModeElement):null;
    const quickDesignForm=document.querySelector('#networkQuickDesignForm'),quickDesignOptions=document.querySelector('#networkQuickDesignOptions'),checkModeInfo=document.querySelector('#networkCheckModeInfo'),openCheckButton=document.querySelector('#networkOpenHydraulicCheck'),runDesignButton=document.querySelector('#networkRunQuickDesign');
    const designPumps=document.querySelector('#networkDesignPumps'),pumpDesignOptions=document.querySelector('#networkPumpDesignOptions');
    const updatePumpDesignOptions=()=>{if(pumpDesignOptions)pumpDesignOptions.hidden=!designPumps?.checked};
    designPumps?.addEventListener('change',updatePumpDesignOptions);updatePumpDesignOptions();
    const updateAnalysisMode=()=>{const design=quickDesignForm?.querySelector('[name="quick_mode"]:checked')?.value==='DESIGN';if(quickDesignOptions)quickDesignOptions.hidden=!design;if(checkModeInfo)checkModeInfo.hidden=design;if(openCheckButton)openCheckButton.hidden=design;if(runDesignButton)runDesignButton.hidden=!design};
    document.querySelector('#networkAnalysisMode')?.addEventListener('click',()=>{updateAnalysisMode();analysisModeModal?.show()});
    quickDesignForm?.querySelectorAll('[name="quick_mode"]').forEach(input=>input.addEventListener('change',updateAnalysisMode));
    openCheckButton?.addEventListener('click',()=>{analysisModeModal?.hide();setTimeout(()=>hydraulicModal?.show(),180)});
    const designProgress=document.querySelector('#networkDesignProgress'),designProgressTitle=document.querySelector('#networkDesignProgressTitle'),designProgressMessage=document.querySelector('#networkDesignProgressMessage'),designProgressBar=document.querySelector('#networkDesignProgressBar'),designProgressState=document.querySelector('#networkDesignProgressState'),designElapsed=document.querySelector('#networkDesignElapsed'),designProgressSteps=[...document.querySelectorAll('#networkDesignProgressSteps li')],designProgressActions=document.querySelector('#networkDesignProgressActions'),designOpenResult=document.querySelector('#networkDesignOpenResult'),designClose=document.querySelector('#networkDesignClose');
    let designElapsedTimer=null,designStageTimer=null,designStartedAt=0,designStage=0;
    const setDesignStage=stage=>{designStage=Math.max(0,Math.min(stage,designProgressSteps.length-1));designProgressSteps.forEach((item,index)=>{item.classList.toggle('active',index===designStage);item.classList.toggle('done',index<designStage)});if(designProgressBar)designProgressBar.style.width=`${[12,38,68,88][designStage]||88}%`};
    const beginDesignProgress=()=>{
      if(!designProgress)return;designProgress.hidden=false;document.body.classList.add('network-design-running');designProgress.classList.remove('success','failed');
      designProgressTitle.textContent='Desain sedang diproses';designProgressMessage.textContent='Jangan menutup halaman. Perhitungan berlangsung di latar tanpa membuka atau memuat ulang tab lain.';designProgressState.textContent='Sedang berjalan';designProgressActions.hidden=true;designStartedAt=Date.now();setDesignStage(0);
      clearInterval(designElapsedTimer);clearInterval(designStageTimer);
      designElapsedTimer=setInterval(()=>{const seconds=Math.floor((Date.now()-designStartedAt)/1000);designElapsed.textContent=`Waktu berjalan ${String(Math.floor(seconds/60)).padStart(2,'0')}:${String(seconds%60).padStart(2,'0')}`},1000);
      designStageTimer=setInterval(()=>{if(designStage<2)setDesignStage(designStage+1)},6000);
    };
    const finishDesignProgress=payload=>{
      clearInterval(designElapsedTimer);clearInterval(designStageTimer);const success=!!payload?.success;
      designProgress?.classList.add(success?'success':'failed');designProgressTitle.textContent=success?'Desain jaringan selesai':'Desain belum dapat diterapkan';designProgressMessage.textContent=payload?.message||(success?'Hasil desain berhasil diterapkan.':'Terjadi kesalahan saat menjalankan desain.');designProgressState.textContent=success?'Selesai':'Perlu perbaikan data';
      if(success){setDesignStage(3);designProgressSteps.forEach(item=>{item.classList.remove('active');item.classList.add('done')});if(designProgressBar)designProgressBar.style.width='100%'}
      if(designOpenResult){designOpenResult.hidden=!success;designOpenResult.href=payload?.redirect||window.location.href}if(designProgressActions)designProgressActions.hidden=false;if(runDesignButton){runDesignButton.disabled=false;runDesignButton.innerHTML='<i class="bi bi-magic"></i> Jalankan dan Terapkan Desain'};
    };
    designClose?.addEventListener('click',()=>{if(designProgress){designProgress.hidden=true;document.body.classList.remove('network-design-running')}});
    quickDesignForm?.addEventListener('submit',async event=>{
      event.preventDefault();if(quickDesignForm.querySelector('[name="quick_mode"]:checked')?.value!=='DESIGN')return;if(!quickDesignForm.checkValidity()){quickDesignForm.reportValidity();return}
      localStorage.removeItem(projectStorageKey('simma-network-hydraulic-results'));if(runDesignButton){runDesignButton.disabled=true;runDesignButton.textContent='Proses berjalan...'}analysisModeModal?.hide();beginDesignProgress();
      try{
        const body=new FormData(quickDesignForm);body.set('_async','1');
        const response=await fetch(quickDesignForm.action,{method:'POST',headers:{'Accept':'application/json'},body});const text=await response.text();let payload;
        try{payload=JSON.parse(text)}catch(error){throw new Error('Respons proses desain tidak dapat dibaca. Muat ulang halaman lalu coba kembali.')}
        finishDesignProgress(payload);
      }catch(error){finishDesignProgress({success:false,message:error?.message||'Layanan desain tidak dapat dihubungi.'})}
    });
    const typeLabel={source:'Sumber Air',reservoir:'Reservoir',service_area:'Wilayah Layanan',node:'Titik Manual'};
    const nodeKindIcon={junction:'bi-circle-fill',source:'bi-droplet-fill',reservoir:'bi-box-fill',tank:'bi-database-fill',pompa:'bi-gear-wide-connected',valve:'bi-hourglass-split',meter:'bi-speedometer2'};
    const createModeKey=projectStorageKey('simma-network-create-mode');
    let connectionMode=false,placingNode=false,drawPipeMode=false,drawLinkType='PIPE',drawOrigin=null,drawPointerId=null,selectedOrigin=null,dragged=false,cameraMoved=false;
    const viewport=networkBoard.closest('.network-board-scroll'),worldWidth=12000,worldHeight=8000,logicalWidth=1100,logicalHeight=650,worldOriginX=(worldWidth-logicalWidth)/2,worldOriginY=(worldHeight-logicalHeight)/2;
    networkBoard.style.width=`${worldWidth}px`;networkBoard.style.height=`${worldHeight}px`;
    nodes.forEach(node=>{node.worldX=worldOriginX+(+node.x||0)/100*logicalWidth;node.worldY=worldOriginY+(+node.y||0)/100*logicalHeight});
    const nodeElements={};
    document.querySelectorAll('.network-node').forEach(element=>{const node=nodeByKey[element.dataset.nodeKey];nodeElements[element.dataset.nodeKey]=element;if(node){element.style.left=`${node.worldX}px`;element.style.top=`${node.worldY}px`}}); 
    const outputToggle=document.querySelector('#networkOutputToggle'),outputOptions=document.querySelector('#networkOutputOptions'),outputStateElement=document.querySelector('#networkOutputState'),outputTime=document.querySelector('#networkOutputTime'),outputPlay=document.querySelector('#networkOutputPlay');
    outputToggle?.addEventListener('click',()=>{const open=outputToggle.getAttribute('aria-expanded')!=='true';outputToggle.setAttribute('aria-expanded',String(open));outputOptions.hidden=!open});
    const outputInputs=[...document.querySelectorAll('[data-network-output]')],outputState={};
    let savedOutputState={};try{savedOutputState=JSON.parse(localStorage.getItem(projectStorageKey('simma-network-output-layers'))||'{}')}catch(error){}
    outputInputs.forEach(input=>{if(Object.hasOwn(savedOutputState,input.dataset.networkOutput))input.checked=!!savedOutputState[input.dataset.networkOutput];outputState[input.dataset.networkOutput]=input.checked});
    const outputFontScale=document.querySelector('#networkOutputFontScale'),outputFontScaleValue=document.querySelector('#networkOutputFontScaleValue');
    const outputLabelScale=document.querySelector('#networkOutputLabelScale'),outputLabelScaleValue=document.querySelector('#networkOutputLabelScaleValue');
    const outputLineScale=document.querySelector('#networkOutputLineScale'),outputLineScaleValue=document.querySelector('#networkOutputLineScaleValue');
    const outputMarkerScale=document.querySelector('#networkOutputMarkerScale'),outputMarkerScaleValue=document.querySelector('#networkOutputMarkerScaleValue');
    let outputDisplaySettings={fontScale:100,labelScale:100,lineScale:100,markerScale:100};
    try{outputDisplaySettings={...outputDisplaySettings,...JSON.parse(localStorage.getItem(projectStorageKey('simma-network-output-display'))||'{}')}}catch(error){}
    const clampOutput=(value,min,max)=>Math.max(min,Math.min(max,+value||100));
    outputDisplaySettings.fontScale=clampOutput(outputDisplaySettings.fontScale,70,180);outputDisplaySettings.labelScale=clampOutput(outputDisplaySettings.labelScale,70,180);outputDisplaySettings.lineScale=clampOutput(outputDisplaySettings.lineScale,60,200);outputDisplaySettings.markerScale=clampOutput(outputDisplaySettings.markerScale,60,200);
    const applyOutputDisplaySettings=()=>{
      networkBoard.style.setProperty('--network-output-font-scale',outputDisplaySettings.fontScale/100);networkBoard.style.setProperty('--network-output-label-scale',outputDisplaySettings.labelScale/100);networkBoard.style.setProperty('--network-output-line-scale',outputDisplaySettings.lineScale/100);networkBoard.style.setProperty('--network-output-marker-scale',outputDisplaySettings.markerScale/100);
      [[outputFontScale,outputFontScaleValue,'fontScale'],[outputLabelScale,outputLabelScaleValue,'labelScale'],[outputLineScale,outputLineScaleValue,'lineScale'],[outputMarkerScale,outputMarkerScaleValue,'markerScale']].forEach(([input,output,key])=>{if(input)input.value=outputDisplaySettings[key];if(output)output.textContent=`${outputDisplaySettings[key]}%`});
    };
    const persistOutputDisplaySettings=()=>localStorage.setItem(projectStorageKey('simma-network-output-display'),JSON.stringify(outputDisplaySettings));
    const selectedOutputPeriod=()=>{
      if(!hydraulicOutputResults?.available)return null;
      if(outputTime?.value&&outputTime.value!=='latest')return hydraulicOutputResults.periods?.find(period=>period.time===outputTime.value)||hydraulicOutputResults.latest;
      return hydraulicOutputResults.latest;
    };
    const applyNodeOutput=()=>{
      const period=selectedOutputPeriod();
      Object.entries(nodeElements).forEach(([key,element])=>{
        element.querySelector('.node-output-label')?.remove();element.classList.remove('output-low','output-critical','output-good');
        const result=period?.nodes?.[key];if(!result)return;
        const parts=[];
        if(outputState['node-pressure'])parts.push(`P ${formatWaterNumber(result.pressure_m)} m`);
        if(outputState['node-head'])parts.push(`H ${formatWaterNumber(result.head_m)} m`);
        if(outputState['node-demand'])parts.push(`Q ${formatWaterNumber(result.delivered_demand_lps)} L/s`);
        if(outputState['node-requested'])parts.push(`Qreq ${formatWaterNumber(result.requested_demand_lps)} L/s`);
        if(outputState['node-deficit'])parts.push(`Defisit ${formatWaterNumber(result.demand_deficit_lps)} L/s`);
        if(outputState['node-fulfillment'])parts.push(`Terpenuhi ${formatWaterNumber(result.fulfillment_percent)}%`);
        if(outputState['node-quality'])parts.push(`Kualitas ${result.quality??'—'}`);
        if(outputState['node-status'])parts.push(String(result.status||'').replaceAll('_',' '));
        if(parts.length){const label=document.createElement('span');label.className='node-output-label';label.textContent=parts.join(' · ');element.append(label)}
        if(outputState['color-pressure'])element.classList.add(result.status==='tekanan_negatif'?'output-critical':result.status==='tekanan_rendah'?'output-low':'output-good');
      });
    };
    const columnPositions={'.source-title':.12,'.reservoir-title':.5,'.area-title':.88};
    Object.entries(columnPositions).forEach(([selector,factor])=>{const title=networkBoard.querySelector(selector);if(title){title.style.left=`${worldOriginX+logicalWidth*factor}px`;title.style.top=`${worldOriginY+15}px`}});
    let camera={scale:1,panX:0,panY:0},hasSavedCamera=false;
    try{
      const savedCamera=JSON.parse(localStorage.getItem(projectStorageKey('simma-network-camera'))||'null');
      if(savedCamera&&[savedCamera.scale,savedCamera.panX,savedCamera.panY].every(Number.isFinite)){camera={scale:Math.max(.25,Math.min(4,savedCamera.scale)),panX:savedCamera.panX,panY:savedCamera.panY};hasSavedCamera=true}
    }catch(error){}
    const persistCamera=()=>localStorage.setItem(projectStorageKey('simma-network-camera'),JSON.stringify(camera));
    const applyCamera=()=>{networkBoard.style.transform=`matrix(${camera.scale},0,0,${camera.scale},${camera.panX},${camera.panY})`};
    const screenToWorld=(clientX,clientY)=>{const rect=viewport.getBoundingClientRect();return{x:(clientX-rect.left-camera.panX)/camera.scale,y:(clientY-rect.top-camera.panY)/camera.scale}};
    const centerCamera=()=>{const rect=viewport.getBoundingClientRect();camera.scale=1;camera.panX=rect.width/2-(worldOriginX+logicalWidth/2);camera.panY=rect.height/2-(worldOriginY+logicalHeight/2);applyCamera();persistCamera()};
    const setLogicalPosition=(node,x,y)=>{node.x=x;node.y=y;node.worldX=worldOriginX+x/100*logicalWidth;node.worldY=worldOriginY+y/100*logicalHeight;const element=nodeElements[node.key];if(element){element.style.left=`${node.worldX}px`;element.style.top=`${node.worldY}px`}};
    const layerInputs=[...document.querySelectorAll('[data-network-layer]')],layerState={};
    let savedLayers={};try{savedLayers=JSON.parse(localStorage.getItem(projectStorageKey('simma-network-layers'))||'{}')}catch(error){}
    layerInputs.forEach(input=>{if(Object.hasOwn(savedLayers,input.dataset.networkLayer))input.checked=!!savedLayers[input.dataset.networkLayer];layerState[input.dataset.networkLayer]=input.checked});
    const applyNodeLayers=()=>['node-name','node-code','node-kind','node-elevation','node-demand','node-description'].forEach(layer=>networkBoard.classList.toggle(`show-${layer}`,!!layerState[layer]));
    const fontScaleInput=document.querySelector('#networkFontScale'),fontScaleOutput=document.querySelector('#networkFontScaleValue');
    const arrowDirectionInput=document.querySelector('#networkArrowDirection'),arrowDirectionOutput=document.querySelector('#networkArrowDirectionValue');
    const arrowScaleInput=document.querySelector('#networkArrowScale'),arrowScaleOutput=document.querySelector('#networkArrowScaleValue');
    const pointScaleInput=document.querySelector('#networkPointScale'),pointScaleOutput=document.querySelector('#networkPointScaleValue');
    let displaySettings={fontScale:100,arrowDirection:1,arrowScale:100,pointScale:100};
    try{displaySettings={...displaySettings,...JSON.parse(localStorage.getItem(projectStorageKey('simma-network-display-settings'))||'{}')}}catch(error){}
    displaySettings.fontScale=Math.max(70,Math.min(170,+displaySettings.fontScale||100));
    const savedArrowDirection=Number(displaySettings.arrowDirection);displaySettings.arrowDirection=Number.isFinite(savedArrowDirection)?Math.max(-1,Math.min(1,savedArrowDirection)):1;
    displaySettings.arrowScale=Math.max(60,Math.min(180,+displaySettings.arrowScale||100));
    displaySettings.pointScale=Math.max(60,Math.min(180,+displaySettings.pointScale||100));
    const persistDisplaySettings=()=>localStorage.setItem(projectStorageKey('simma-network-display-settings'),JSON.stringify(displaySettings));
    const applyDisplaySettings=()=>{
      const fontScale=displaySettings.fontScale/100,arrowScale=displaySettings.arrowScale/100,pointScale=displaySettings.pointScale/100;
      networkBoard.style.setProperty('--network-font-scale',fontScale);networkBoard.style.setProperty('--network-node-scale',pointScale);
      if(fontScaleInput)fontScaleInput.value=displaySettings.fontScale;if(fontScaleOutput)fontScaleOutput.textContent=`${displaySettings.fontScale}%`;
      if(arrowDirectionInput)arrowDirectionInput.value=displaySettings.arrowDirection;if(arrowDirectionOutput)arrowDirectionOutput.textContent=displaySettings.arrowDirection<0?'Tujuan → Asal':displaySettings.arrowDirection===0?'Tanpa panah':'Asal → Tujuan';
      if(arrowScaleInput)arrowScaleInput.value=displaySettings.arrowScale;if(arrowScaleOutput)arrowScaleOutput.textContent=`${displaySettings.arrowScale}%`;
      if(pointScaleInput)pointScaleInput.value=displaySettings.pointScale;if(pointScaleOutput)pointScaleOutput.textContent=`${displaySettings.pointScale}%`;
      ['networkArrowActive','networkArrowInactive'].forEach(id=>{const marker=document.querySelector(`#${id}`);if(marker){marker.setAttribute('markerWidth',String(14*arrowScale));marker.setAttribute('markerHeight',String(14*arrowScale));marker.style.overflow='visible'}});
    };

    let selectedDiagramObject=null;
    const clearDiagramSelection=()=>{
      selectedDiagramObject=null;
      Object.values(nodeElements).forEach(element=>element.classList.remove('selected-object'));
      svg.querySelectorAll('.network-route-shape').forEach(element=>element.classList.remove('selected-object'));
    };
    const selectDiagramObject=(type,data)=>{
      clearDiagramSelection();selectedDiagramObject={type,data};
      if(type==='node')nodeElements[data.key]?.classList.add('selected-object');
      else svg.querySelector(`.network-route-shape[data-route-id="${data.id}"]`)?.classList.add('selected-object');
    };
    const drawRoutes=()=>{
      [...svg.querySelectorAll('.network-route-shape')].forEach(element=>element.remove());
      const namespace='http://www.w3.org/2000/svg';
      const pairTotals={},pairIndexes={};
      routes.forEach(route=>{const pair=[route.origin_key,route.destination_key].sort().join('|');pairTotals[pair]=(pairTotals[pair]||0)+1});
      routes.forEach(route=>{
        const origin=nodeByKey[route.origin_key],destination=nodeByKey[route.destination_key];if(!origin||!destination)return;
        const centerX1=origin.worldX,centerY1=origin.worldY,centerX2=destination.worldX,centerY2=destination.worldY;
        const pair=[route.origin_key,route.destination_key].sort().join('|'),parallelIndex=pairIndexes[pair]||0,totalParallel=pairTotals[pair],offset=(parallelIndex-(totalParallel-1)/2)*34;pairIndexes[pair]=parallelIndex+1;
        const centerDx=centerX2-centerX1,centerDy=centerY2-centerY1,centerLength=Math.max(1,Math.hypot(centerDx,centerDy)),unitX=centerDx/centerLength,unitY=centerDy/centerLength;
        const trim=Math.min(22*displaySettings.pointScale/100,centerLength*.22),x1=centerX1+unitX*trim,y1=centerY1+unitY*trim,x2=centerX2-unitX*trim,y2=centerY2-unitY*trim;
        const dx=x2-x1,dy=y2-y1,length=Math.max(1,Math.hypot(dx,dy)),normalX=-dy/length,normalY=dx/length;
        const control1X=x1+dx*.34+normalX*offset,control1Y=y1+dy*.34+normalY*offset,control2X=x1+dx*.66+normalX*offset,control2Y=y1+dy*.66+normalY*offset;
        const pathValue=`M ${x1} ${y1} C ${control1X} ${control1Y}, ${control2X} ${control2Y}, ${x2} ${y2}`;
        const labelPathValue=x2>=x1?pathValue:`M ${x2} ${y2} C ${control2X} ${control2Y}, ${control1X} ${control1Y}, ${x1} ${y1}`;
        const group=document.createElementNS(namespace,'g');group.classList.add('network-route-shape',`link-${String(route.link_type||'PIPE').toLowerCase()}`);group.dataset.routeId=route.id;
        if(selectedDiagramObject?.type==='route'&&String(selectedDiagramObject.data.id)===String(route.id))group.classList.add('selected-object');
        const hit=document.createElementNS(namespace,'path');hit.setAttribute('d',pathValue);hit.setAttribute('class','network-route-hit');
        const path=document.createElementNS(namespace,'path');path.setAttribute('d',pathValue);path.setAttribute('id',`networkRoutePath${route.id}`);path.setAttribute('class',`network-route-path ${route.status==='aktif'?'active':'inactive'} ${String(route.link_type||'PIPE').toLowerCase()}`);
        const labelGuide=document.createElementNS(namespace,'path');labelGuide.setAttribute('d',labelPathValue);labelGuide.setAttribute('id',`networkRouteLabelPath${route.id}`);labelGuide.setAttribute('class','network-route-label-guide');
        const result=selectedOutputPeriod()?.links?.[`link:${route.id}`],markerUrl=`url(#${route.status==='aktif'?'networkArrowActive':'networkArrowInactive'})`;
        const resultDirection=outputState['link-direction']&&result?(result.flow_lps>=0?1:-1):displaySettings.arrowDirection;
        if(resultDirection>0)path.setAttribute('marker-end',markerUrl);else if(resultDirection<0)path.setAttribute('marker-start',markerUrl);
        const labelParts=[];
        if(layerState['pipe-name'])labelParts.push(`P-${String(route.id).padStart(3,'0')} · ${route.route_name}`);
        if(layerState['pipe-type'])labelParts.push(route.pipe_type||'material belum diisi');
        if(layerState['pipe-length'])labelParts.push(`${formatWaterNumber(route.pipe_length_m)} m`);
        if(layerState['pipe-diameter'])labelParts.push(`Ø ${formatWaterNumber(route.pipe_diameter_mm)} mm`);
        if(layerState['pipe-capacity'])labelParts.push(`kap. ${formatWaterNumber(route.max_pipe_capacity_lps)} L/s`);
        if(layerState['pipe-flow'])labelParts.push(`debit ${formatWaterNumber(route.planned_flow_lps)} L/s`);
        if(layerState['pipe-loss'])labelParts.push(`loss ${formatWaterNumber(route.loss_percent)}%`);
        if(layerState['pipe-roughness'])labelParts.push(`kekasaran ${formatWaterNumber(route.roughness_coefficient)}`);
        if(layerState['pipe-minor-loss'])labelParts.push(`minor loss ${formatWaterNumber(route.minor_loss_coefficient)}`);
        if(layerState['pipe-check-valve'])labelParts.push(+route.check_valve===1?'check valve':'tanpa check valve');
        if(layerState['pipe-pump'])labelParts.push((route.pump_status||'tanpa_pompa').replaceAll('_',' '),`pompa ${formatWaterNumber(route.pump_capacity_lps)} L/s · ${formatWaterNumber(route.pump_hours)} jam`);
        if(layerState['pipe-status'])labelParts.push((route.status||'').replaceAll('_',' '));
        if(layerState['pipe-description'])labelParts.push(route.description||'keterangan belum diisi');
        const linkType=String(route.link_type||'PIPE').toUpperCase(),isPump=linkType==='PUMP',pumpRunning=isPump&&Math.abs(Number(result?.flow_lps||0))>.0001;
        if(result&&isPump&&(outputState['link-flow']||outputState['link-velocity']))labelParts.push(pumpRunning?'Pompa ON':'Pompa OFF');
        if(result&&outputState['link-flow'])labelParts.push(`${isPump?'Q pompa':'Q hasil'} ${formatWaterNumber(result.flow_lps)} L/s`);
        if(result&&outputState['link-velocity'])labelParts.push(isPump?(pumpRunning?`Head +${formatWaterNumber(result.pump_head_gain_m)} m`:'Kontrol level reservoir'):`V ${formatWaterNumber(result.velocity_mps)} m/s`);
        if(result&&!isPump&&outputState['link-unit-headloss']&&result.unit_headloss_m_per_km!==null)labelParts.push(`hf ${formatWaterNumber(result.unit_headloss_m_per_km)} m/km`);
        if(result&&outputState['link-headloss'])labelParts.push(isPump?`Head pompa +${formatWaterNumber(result.pump_head_gain_m)} m`:`Î”H ${formatWaterNumber(result.headloss_m)} m`);
        if(result&&outputState['link-direction'])labelParts.push(result.direction==='asal_ke_tujuan'?'aliran asal â†’ tujuan':'aliran tujuan â†’ asal');
        if(result&&outputState['link-status'])labelParts.push(`engine ${result.status}`);
        if(result&&outputState['color-velocity'])group.classList.add(isPump?(pumpRunning?'output-good':'output-off'):(result.velocity_mps>3?'output-critical':result.velocity_mps>2?'output-warning':'output-good'));
        const outputTextScale=result?outputDisplaySettings.fontScale/100:1,outputLabelMultiplier=result?outputDisplaySettings.labelScale/100:1;
        const labelRows=[],fontSize=11*displaySettings.fontScale/100*outputTextScale;
        for(let rowIndex=0;rowIndex<labelParts.length;rowIndex+=3){
          const label=document.createElementNS(namespace,'text');label.setAttribute('class','network-route-label');label.setAttribute('dy',String((-8-(rowIndex/3)*(fontSize+3))*outputLabelMultiplier));label.style.fontSize=`${fontSize}px`;
          const textPath=document.createElementNS(namespace,'textPath');textPath.setAttribute('href',`#networkRouteLabelPath${route.id}`);textPath.setAttribute('startOffset','50%');textPath.setAttribute('text-anchor','middle');textPath.textContent=labelParts.slice(rowIndex,rowIndex+3).join(' · ');label.append(textPath);labelRows.push(label);
        }
        const title=document.createElementNS(namespace,'title');title.textContent=`${route.route_name}: ${route.origin_name} → ${route.destination_name}, ${formatWaterNumber(route.pipe_length_m)} m, Ø ${formatWaterNumber(route.pipe_diameter_mm)} mm, ${formatWaterNumber(route.planned_flow_lps)} L/s`;
        const showRouteFromLine=event=>{event.stopPropagation();showRouteInspector(route)};
        const editRouteFromLine=event=>{event.stopPropagation();openRoute(route)};
        const pumpMarker=[];if((route.link_type||'PIPE')==='PUMP'){const markerX=.125*x1+.375*control1X+.375*control2X+.125*x2,markerY=.125*y1+.375*control1Y+.375*control2Y+.125*y2;const circle=document.createElementNS(namespace,'circle');circle.setAttribute('class','network-pump-symbol');circle.setAttribute('cx',String(markerX));circle.setAttribute('cy',String(markerY));circle.setAttribute('r','13');const symbol=document.createElementNS(namespace,'text');symbol.setAttribute('class','network-pump-symbol-text');symbol.setAttribute('x',String(markerX));symbol.setAttribute('y',String(markerY));symbol.textContent='P';pumpMarker.push(circle,symbol)}
        group.append(labelGuide,hit,path,...pumpMarker,...labelRows,title);hit.addEventListener('click',showRouteFromLine);path.addEventListener('click',showRouteFromLine);hit.addEventListener('dblclick',editRouteFromLine);path.addEventListener('dblclick',editRouteFromLine);svg.append(group);
      });
    };
    applyNodeLayers();applyDisplaySettings();
    layerInputs.forEach(input=>input.addEventListener('change',()=>{layerState[input.dataset.networkLayer]=input.checked;localStorage.setItem(projectStorageKey('simma-network-layers'),JSON.stringify(layerState));applyNodeLayers();drawRoutes()}));
    fontScaleInput?.addEventListener('input',()=>{displaySettings.fontScale=+fontScaleInput.value;persistDisplaySettings();applyDisplaySettings();drawRoutes()});
    arrowDirectionInput?.addEventListener('input',()=>{displaySettings.arrowDirection=+arrowDirectionInput.value;persistDisplaySettings();applyDisplaySettings();drawRoutes()});
    arrowScaleInput?.addEventListener('input',()=>{displaySettings.arrowScale=+arrowScaleInput.value;persistDisplaySettings();applyDisplaySettings();drawRoutes()});
    pointScaleInput?.addEventListener('input',()=>{displaySettings.pointScale=+pointScaleInput.value;persistDisplaySettings();applyDisplaySettings();drawRoutes()});
    const showRouteInspector=route=>{
      selectDiagramObject('route',route);
      const metrics=[
        ['Titik asal',route.origin_name],['Titik tujuan',route.destination_name],
        ['Material',route.pipe_type||'Belum diisi'],['Panjang',`${formatWaterNumber(route.pipe_length_m)} m`],
        ['Diameter',`${formatWaterNumber(route.pipe_diameter_mm)} mm`],['Kapasitas maksimum',`${formatWaterNumber(route.max_pipe_capacity_lps)} L/s`],
        ['Debit rencana',`${formatWaterNumber(route.planned_flow_lps)} L/s`],['Kehilangan air',`${formatWaterNumber(route.loss_percent)}%`],
        ['Koefisien kekasaran',formatWaterNumber(route.roughness_coefficient)],['Minor loss',formatWaterNumber(route.minor_loss_coefficient)],
        ['Check valve',+route.check_valve===1?'Ya · satu arah':'Tidak'],['Pompa',(route.pump_status||'tanpa_pompa').replaceAll('_',' ')],
        ['Kapasitas pompa',`${formatWaterNumber(route.pump_capacity_lps)} L/s`],['Jam operasi',`${formatWaterNumber(route.pump_hours)} jam`],
        ['Status',(route.status||'').replaceAll('_',' ')]
      ];
      const result=selectedOutputPeriod()?.links?.[`link:${route.id}`];
      if(result){metrics.push(['OUTPUT · Debit',`${formatWaterNumber(result.flow_lps)} L/s`]);if((route.link_type||'PIPE')==='PUMP')metrics.push(['OUTPUT · Head pompa',`${formatWaterNumber(result.pump_head_gain_m)} m`]);else metrics.push(['OUTPUT · Kecepatan',`${formatWaterNumber(result.velocity_mps)} m/s`],['OUTPUT · Headloss',`${formatWaterNumber(result.headloss_m)} m`],['OUTPUT · Headloss/km',`${formatWaterNumber(result.unit_headloss_m_per_km)} m/km`]);metrics.push(['OUTPUT · Arah',result.direction==='asal_ke_tujuan'?'Asal → tujuan':'Tujuan → asal'],['OUTPUT · Status',result.status]);}
      inspector.innerHTML=`<div class="inspector-head route"><span class="node-icon route"><i class="bi bi-bezier2"></i></span><div><small>Jalur Pipa P-${String(route.id).padStart(3,'0')}</small><h3>${escapeHtml(route.route_name)}</h3><p>${escapeHtml(route.origin_name)} → ${escapeHtml(route.destination_name)}</p></div></div><dl class="inspector-metrics">${metrics.map(metric=>`<div><dt>${escapeHtml(metric[0])}</dt><dd>${escapeHtml(metric[1])}</dd></div>`).join('')}</dl>${route.description?`<div class="inspector-description"><strong>Keterangan</strong><p>${escapeHtml(route.description)}</p></div>`:''}<small class="inspector-help"><i class="bi bi-mouse2"></i> Klik dua kali garis pipa untuk mengedit langsung.</small>`;
    };
    const showInspector=node=>{
      const connectedPipes=routes.filter(route=>route.origin_key===node.key||route.destination_key===node.key).length;
      const metrics=node.type==='source'
        ?[['Debit normal',`${formatWaterNumber(node.normal_flow)} L/s`],['Debit sensor',`${formatWaterNumber(node.sensor_flow)} L/s`],['Elevasi',`${formatWaterNumber(node.elevation)} m`],['Status',node.status]]
        :node.type==='reservoir'
          ?[['Kapasitas efektif',`${formatWaterNumber(node.capacity)} m³`],['Volume awal',`${formatWaterNumber(node.initial_volume)} m³`],['Elevasi',`${formatWaterNumber(node.elevation)} m`],['Status',node.status]]
          :node.type==='service_area'
            ?[['Penduduk',new Intl.NumberFormat('id-ID').format(node.population||0)],['Kebutuhan puncak',`${formatWaterNumber(node.demand)} L/s`],['Prioritas',(node.priority||'').replaceAll('_',' ')],['Jenis','Wilayah layanan']]
            :[['Jenis node',(node.node_kind||'junction').replaceAll('_',' ')],['Jumlah pipa terhubung',`${connectedPipes} pipa`],['Elevasi',`${formatWaterNumber(node.elevation)} m`],['Base demand',`${formatWaterNumber(node.base_demand)} L/s`],['Status',node.status]];
      const result=selectedOutputPeriod()?.nodes?.[node.key];
      if(result)metrics.push(['OUTPUT · Tekanan',`${formatWaterNumber(result.pressure_m)} m`],['OUTPUT · Total head',`${formatWaterNumber(result.head_m)} m`],['OUTPUT · Demand aktual',`${formatWaterNumber(result.delivered_demand_lps)} L/s`],['OUTPUT · Demand rencana',`${formatWaterNumber(result.requested_demand_lps)} L/s`],['OUTPUT · Defisit',`${formatWaterNumber(result.demand_deficit_lps)} L/s`],['OUTPUT · Pemenuhan',`${formatWaterNumber(result.fulfillment_percent)}%`],['OUTPUT · Kualitas',result.quality??'Belum dianalisis'],['OUTPUT · Status',String(result.status||'').replaceAll('_',' ')]);
      const editAction=node.type==='node'?'<button class="btn btn-outline-primary w-100" type="button" id="inspectorEditNode"><i class="bi bi-pencil-square"></i> Isi / Ubah Data Titik</button>':`<a class="btn btn-outline-primary w-100" href="${escapeHtml(node.edit_url)}"><i class="bi bi-pencil-square"></i> Ubah Data Titik</a>`;
      const inspectorIcon=node.type==='node'?(nodeKindIcon[node.node_kind]||'bi-circle-fill'):node.type==='source'?'bi-droplet-fill':node.type==='reservoir'?'bi-box-fill':'bi-houses-fill';
      inspector.innerHTML=`<div class="inspector-head"><span class="node-icon ${escapeHtml(node.type.replaceAll('_','-'))} kind-${escapeHtml(node.node_kind||'master')}"><i class="bi ${inspectorIcon}"></i></span><div><small>${escapeHtml(typeLabel[node.type])}</small><h3>${escapeHtml(node.name)}</h3><p>${escapeHtml(node.code)}</p></div></div><dl class="inspector-metrics">${metrics.map(metric=>`<div><dt>${escapeHtml(metric[0])}</dt><dd>${escapeHtml(metric[1])}</dd></div>`).join('')}</dl><div class="inspector-actions">${editAction}<button class="btn btn-primary w-100" type="button" id="inspectorConnect"><i class="bi bi-bezier2"></i> Tambah Pipa dari Titik Ini</button></div><small class="inspector-help"><i class="bi bi-bezier2"></i> Satu titik dapat terhubung dengan banyak pipa tanpa batas.</small>`;
      inspector.querySelector('#inspectorConnect')?.addEventListener('click',()=>startConnection(node));
      inspector.querySelector('#inspectorEditNode')?.addEventListener('click',()=>openNode(node));
    };
    const startConnection=node=>{
      if(drawPipeMode)stopDirectDraw();
      connectionMode=true;placingNode=false;networkBoard.classList.remove('placing-node');selectedOrigin=null;
      sessionStorage.setItem(createModeKey,'connect');document.querySelector('#networkAddRoute')?.classList.add('active');document.querySelector('#networkAddNode')?.classList.remove('active');
      Object.values(nodeElements).forEach(element=>element.classList.remove('selected-origin','valid-target','invalid-target'));
      if(node){
        if(!['source','reservoir','node'].includes(node.type)){hint.innerHTML='<i class="bi bi-exclamation-triangle"></i> Wilayah layanan hanya dapat menjadi titik tujuan.';return}
        selectedOrigin=node;nodeElements[node.key]?.classList.add('selected-origin');markTargets(node);
        hint.innerHTML=`<i class="bi bi-record-circle"></i> Asal: <strong>${escapeHtml(node.name)}</strong>. Klik reservoir atau wilayah tujuan.`;
      }else hint.innerHTML='<i class="bi bi-cursor-fill"></i> Mode pipa berulang aktif. Pilih titik asal; tekan tombol lagi atau Esc untuk selesai.';
      hint.classList.add('active');
    };
    const markTargets=origin=>{
      nodes.forEach(node=>{
        if(node.key===origin.key)return;
        const valid=(origin.type==='source'&&['reservoir','service_area','node'].includes(node.type))||(origin.type==='reservoir'&&['service_area','node'].includes(node.type))||(origin.type==='node'&&['reservoir','service_area','node'].includes(node.type));
        nodeElements[node.key]?.classList.add(valid?'valid-target':'invalid-target');
      });
    };
    const clearConnection=()=>{
      connectionMode=false;selectedOrigin=null;document.querySelector('#networkAddRoute')?.classList.remove('active');hint.classList.remove('active');hint.innerHTML='<i class="bi bi-cursor-fill"></i> Tambahkan titik atau pilih Gambar Pipa Langsung untuk mulai menggambar jaringan.';
      Object.values(nodeElements).forEach(element=>element.classList.remove('selected-origin','valid-target','invalid-target'));
    };
    const isValidRoute=(origin,target)=>!!origin&&!!target&&origin.key!==target.key&&((origin.type==='source'&&['reservoir','service_area','node'].includes(target.type))||(origin.type==='reservoir'&&['service_area','node'].includes(target.type))||(origin.type==='node'&&['reservoir','service_area','node'].includes(target.type)));
    const drawRouteButton=document.querySelector('#networkDrawRoute'),drawPumpButton=document.querySelector('#networkDrawPump');
    const clearDrawPreview=()=>{svg.querySelector('.network-route-preview')?.remove();drawOrigin=null;drawPointerId=null;Object.values(nodeElements).forEach(element=>element.classList.remove('selected-origin','valid-target','invalid-target'))};
    const stopDirectDraw=()=>{
      drawPipeMode=false;networkBoard.classList.remove('drawing-pipe','drawing-pump');drawRouteButton?.classList.remove('active');drawPumpButton?.classList.remove('active');clearDrawPreview();
    };
    const toggleDirectDraw=(requestedType='PIPE')=>{
      const deactivate=drawPipeMode&&drawLinkType===requestedType;drawPipeMode=!deactivate;drawLinkType=requestedType;connectionMode=false;placingNode=false;selectedOrigin=null;networkBoard.classList.remove('placing-node');clearDrawPreview();
      networkBoard.classList.toggle('drawing-pipe',drawPipeMode&&drawLinkType==='PIPE');networkBoard.classList.toggle('drawing-pump',drawPipeMode&&drawLinkType==='PUMP');drawRouteButton?.classList.toggle('active',drawPipeMode&&drawLinkType==='PIPE');drawPumpButton?.classList.toggle('active',drawPipeMode&&drawLinkType==='PUMP');hint.classList.toggle('active',drawPipeMode);
      document.querySelector('#networkAddNode')?.classList.remove('active');document.querySelector('#networkAddRoute')?.classList.remove('active');
      if(drawPipeMode)sessionStorage.setItem(createModeKey,drawLinkType==='PUMP'?'draw-pump':'draw');else sessionStorage.removeItem(createModeKey);
      hint.innerHTML=drawPipeMode?(drawLinkType==='PUMP'?'<i class="bi bi-gear-wide-connected"></i> Mode gambar pompa aktif. Tarik dari titik masuk ke titik keluar pompa.':'<i class="bi bi-pencil"></i> Mode gambar pipa berulang aktif. Tarik dari asal ke tujuan; tekan tombol lagi atau Esc untuk selesai.'):'<i class="bi bi-cursor-fill"></i> Tambahkan titik atau pilih alat gambar untuk mulai membuat jaringan.';
    };
    const updateDrawPreview=(origin,event)=>{
      const end=screenToWorld(event.clientX,event.clientY),startX=origin.worldX,startY=origin.worldY,endX=end.x,endY=end.y;
      let preview=svg.querySelector('.network-route-preview');if(!preview){preview=document.createElementNS('http://www.w3.org/2000/svg','path');preview.setAttribute('class','network-route-preview');svg.append(preview)}
      preview.setAttribute('d',`M ${startX} ${startY} L ${endX} ${endY}`);
    };
    const quickCreateRoute=async(origin,destination,repeatMode='connect')=>{
      hint.classList.add('active');hint.innerHTML='<i class="bi bi-hourglass-split"></i> Membuat pipa dengan data awal...';
      try{
        const length=Math.max(1,Math.hypot(destination.worldX-origin.worldX,destination.worldY-origin.worldY));
        const body=new URLSearchParams({_token:csrf,project_id:projectId,origin_key:origin.key,destination_key:destination.key,pipe_length_m:length.toFixed(2)});
        const response=await fetch(networkBoard.dataset.createRouteUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});
        const payload=await response.json();if(payload.success){sessionStorage.setItem(createModeKey,repeatMode);window.location.reload()}else throw new Error(payload.message||'Pipa gagal dibuat.');
      }catch(error){hint.innerHTML=`<i class="bi bi-exclamation-triangle"></i> ${escapeHtml(error.message||'Pipa belum dapat dibuat. Silakan coba lagi.')}`;repeatMode==='draw'?toggleDirectDraw():startConnection()}
    };
    const chooseNode=node=>{
      if(!connectionMode)selectDiagramObject('node',node);
      showInspector(node);
      if(!connectionMode)return;
      if(!selectedOrigin){
        if(!['source','reservoir','node'].includes(node.type)){hint.innerHTML='<i class="bi bi-exclamation-triangle"></i> Titik asal harus berupa sumber air, reservoir, atau titik manual.';return}
        selectedOrigin=node;nodeElements[node.key]?.classList.add('selected-origin');markTargets(node);
        hint.innerHTML=`<i class="bi bi-record-circle"></i> Asal: <strong>${escapeHtml(node.name)}</strong>. Pilih titik tujuan.`;
        return;
      }
      if(!isValidRoute(selectedOrigin,node)){hint.innerHTML='<i class="bi bi-exclamation-triangle"></i> Arah tidak valid. Pilih reservoir atau wilayah layanan yang sesuai.';return}
      const origin=selectedOrigin;clearConnection();quickCreateRoute(origin,node,'connect');
    };
    const syncEndpointFields=()=>{
      const origin=nodeByKey[originSelect.value],destination=nodeByKey[destinationSelect.value];
      document.querySelector('#networkOriginType').value=origin?.type||'';document.querySelector('#networkOriginId').value=origin?.id||'';
      document.querySelector('#networkDestinationType').value=destination?.type||'';document.querySelector('#networkDestinationId').value=destination?.id||'';
      document.querySelector('#networkStartElevation').value=origin?.elevation??0;document.querySelector('#networkEndElevation').value=destination?.elevation??0;
      const summary=document.querySelector('#networkRouteSummary');
      summary.innerHTML=origin&&destination?`<strong>${escapeHtml(origin.name)}</strong><i class="bi bi-arrow-right"></i><strong>${escapeHtml(destination.name)}</strong><span>Beda elevasi ${formatWaterNumber((+origin.elevation||0)-(+destination.elevation||0))} m</span>`:'<span>Pilih titik asal dan tujuan.</span>';
      if(origin&&destination&&!document.querySelector('#networkRouteId').value)document.querySelector('#networkRouteName').value=`Jalur ${origin.name} ke ${destination.name}`;
    };
    const materialField=document.querySelector('#networkPipeType'),roughnessField=document.querySelector('#networkRoughness'),roughnessHelp=document.querySelector('#networkRoughnessHelp');
    const roughnessStandards={
      HDPE:{'H-W':150,'D-W':.0015,'C-M':.009},
      PVC:{'H-W':150,'D-W':.0015,'C-M':.009},
      Galvanis:{'H-W':120,'D-W':.15,'C-M':.016},
      Baja:{'H-W':130,'D-W':.045,'C-M':.012},
      Beton:{'H-W':130,'D-W':.3,'C-M':.013}
    };
    const roughnessUnits={'H-W':'faktor C Hazen-Williams','D-W':'mm (kekasaran absolut)','C-M':'Manning n'};
    const updateAutomaticRoughness=()=>{
      const material=materialField?.value||'',formula=headlossFormula?.value||'H-W',automatic=roughnessStandards[material]?.[formula];
      const formulaField=document.querySelector('#networkRoughnessFormula');if(formulaField)formulaField.value=formula;
      if(roughnessField){
        roughnessField.readOnly=automatic!==undefined;
        roughnessField.classList.toggle('bg-light',automatic!==undefined);
        if(automatic!==undefined)roughnessField.value=automatic;
      }
      if(roughnessHelp)roughnessHelp.textContent=automatic!==undefined
        ?`Otomatis: ${automatic} (${roughnessUnits[formula]}) untuk ${material}.`
        :`Isi manual untuk material ${material||'yang belum dipilih'} (${roughnessUnits[formula]}).`;
    };
    materialField?.addEventListener('change',updateAutomaticRoughness);
    headlossFormula?.addEventListener('change',()=>{
      localStorage.setItem(headlossFormulaKey,headlossFormula.value);
      updateAutomaticRoughness();
    });
    const resetRouteForm=()=>{
      form.reset();document.querySelector('#networkRouteId').value='';document.querySelector('#networkRouteMethod').value='';document.querySelector('#networkRouteModalTitle').textContent='Hubungkan Titik';document.querySelector('#networkDeleteRoute')?.classList.add('d-none');
      document.querySelector('#networkLoss').value='0';document.querySelector('#networkPriority').value='1';document.querySelector('#networkStatus').value='aktif';document.querySelector('#networkLinkType').value='PIPE';document.querySelector('#networkRoughness').value='100';document.querySelector('#networkPumpDefinitionMode').value='HEAD';document.querySelector('#networkPumpCurveId').value='';resetPumpCurveEditor();setLinkSection('connection');toggleLinkTypeFields();updateAutomaticRoughness();
    };
    const prepareRoute=(origin,destination)=>{
      resetRouteForm();originSelect.value=origin.key;destinationSelect.value=destination.key;syncEndpointFields();
      const suggested=origin.type==='source'?(+origin.sensor_flow||+origin.normal_flow||0):destination.type==='service_area'?(+destination.demand||0):0;
      document.querySelector('#networkPlannedFlow').value=suggested?Number(suggested.toFixed(4)):'';
    };
    const openRoute=route=>{
      resetRouteForm();document.querySelector('#networkRouteId').value=route.id;document.querySelector('#networkRouteModalTitle').textContent='Edit Jalur Distribusi';
      originSelect.value=route.origin_key;destinationSelect.value=route.destination_key;
      const mappings={networkRouteName:'route_name',networkLinkType:'link_type',networkPipeType:'pipe_type',networkPipeLength:'pipe_length_m',networkGeometricLength:'geometric_length_m',networkPipeDiameter:'pipe_diameter_mm',networkMaterialCode:'material_code',networkInstallationYear:'installation_year',networkMaxCapacity:'max_pipe_capacity_lps',networkRoughness:'roughness_coefficient',networkMinorLoss:'minor_loss_coefficient',networkPlannedFlow:'planned_flow_lps',networkLoss:'loss_percent',networkPriority:'flow_priority',networkMaxVelocity:'max_velocity_mps',networkMaxUnitHeadloss:'max_unit_headloss_m_per_km',networkLeakageModel:'leakage_model',networkPumpCurveId:'pump_curve_id',networkEfficiencyCurveId:'efficiency_curve_id',networkNominalPower:'nominal_power_kw',networkRelativeSpeed:'relative_speed',networkSpeedPattern:'speed_pattern_id',networkUnitCount:'unit_count',networkActiveUnitCount:'active_unit_count',networkOperatingSchedule:'operating_schedule_id',networkControlMode:'control_mode',networkPumpStartLevelLink:'start_level_m',networkPumpStopLevelLink:'stop_level_m',networkPumpStartPressureLink:'start_pressure_m',networkPumpStopPressureLink:'stop_pressure_m',networkValveLinkType:'valve_type',networkValveLinkSetting:'valve_setting',networkInitialStatus:'initial_status',networkStatus:'status',networkDescription:'description',networkPolylineJson:'polyline_json'};
      Object.entries(mappings).forEach(([id,key])=>{const field=document.querySelector(`#${id}`);if(field)field.value=route[key]??''});document.querySelector('#networkPumpDefinitionMode').value=route.pump_curve_id?'HEAD':'POWER';syncPumpCurveEditor();document.querySelector('#networkCheckValve').checked=+route.check_valve===1;document.querySelector('#networkUseManualLength').checked=+route.use_manual_length!==0;document.querySelector('#networkDeleteRoute')?.classList.remove('d-none');toggleLinkTypeFields();syncEndpointFields();updateAutomaticRoughness();modal.show();
    };
    document.querySelector('#networkDeleteRoute')?.addEventListener('click',()=>{if(confirm('Hapus pipa ini? Data pipa akan diarsipkan dan tidak lagi tampil pada jaringan.')){document.querySelector('#networkRouteMethod').value='DELETE';form.submit()}});
    let activeLinkSection='connection';
    const linkTypeSelect=document.querySelector('#networkLinkType');
    const pumpDefinitionMode=document.querySelector('#networkPumpDefinitionMode'),pumpCurveSelect=document.querySelector('#networkPumpCurveId'),pumpCurvePoints=document.querySelector('#networkPumpCurvePoints'),addPumpCurvePoint=document.querySelector('#networkAddPumpCurvePoint'),pumpCurveCode=document.querySelector('#networkPumpCurveCode'),pumpCurveName=document.querySelector('#networkPumpCurveName'),pumpCurveCanvas=document.querySelector('#networkPumpCurveChart');let pumpCurveChart=null;
    const defaultPumpCurvePoints=()=>[{flow_lps:0,head_m:40},{flow_lps:10,head_m:30},{flow_lps:20,head_m:10}];
    const pumpCurveValues=()=>[...(pumpCurvePoints?.querySelectorAll('.pump-curve-point')||[])].map(row=>({flow_lps:+row.querySelector('[name="pump_curve_flow[]"]')?.value||0,head_m:+row.querySelector('[name="pump_curve_head[]"]')?.value||0})).sort((a,b)=>a.flow_lps-b.flow_lps);
    const updatePumpCurveChart=()=>{
      const points=pumpCurveValues();
      if(pumpCurveChart){pumpCurveChart.data.datasets[0].data=points.map(point=>({x:point.flow_lps,y:point.head_m}));pumpCurveChart.update('none');return}
      if(pumpCurveCanvas&&window.Chart)pumpCurveChart=new Chart(pumpCurveCanvas,{
        type:'line',
        data:{datasets:[{label:'Kurva Q–H',data:points.map(point=>({x:point.flow_lps,y:point.head_m})),borderColor:'#e28a00',backgroundColor:'#e28a0020',fill:true,tension:.28,pointRadius:4,pointBackgroundColor:'#fff',pointBorderWidth:2}]},
        options:{responsive:true,maintainAspectRatio:false,parsing:false,scales:{x:{type:'linear',beginAtZero:true,title:{display:true,text:'Debit Q (L/s)'}},y:{beginAtZero:true,title:{display:true,text:'Head H (m)'}}}}
      });
    };
    const renderPumpCurvePoints=(points,readOnly=false)=>{if(!pumpCurvePoints)return;pumpCurvePoints.innerHTML='';points.forEach(point=>{const row=document.createElement('div');row.className='pump-curve-point';row.innerHTML=`<input class="form-control form-control-sm" type="number" min="0" step="any" name="pump_curve_flow[]" value="${escapeHtml(point.flow_lps??point.x??0)}" aria-label="Debit kurva pompa" ${readOnly?'disabled':''}><input class="form-control form-control-sm" type="number" min="0" step="any" name="pump_curve_head[]" value="${escapeHtml(point.head_m??point.y??0)}" aria-label="Head kurva pompa" ${readOnly?'disabled':''}><button class="btn btn-sm btn-outline-danger" type="button" data-remove-pump-point title="Hapus titik" ${readOnly?'disabled':''}><i class="bi bi-trash"></i></button>`;pumpCurvePoints.append(row)});pumpCurvePoints.querySelectorAll('input').forEach(input=>input.addEventListener('input',updatePumpCurveChart));pumpCurvePoints.querySelectorAll('[data-remove-pump-point]').forEach(button=>button.addEventListener('click',()=>{if(pumpCurvePoints.children.length<=2)return;button.closest('.pump-curve-point')?.remove();updatePumpCurveChart()}));updatePumpCurveChart()};
    const syncPumpCurveEditor=()=>{const option=pumpCurveSelect?.selectedOptions?.[0],usingStored=!!pumpCurveSelect?.value;let points=defaultPumpCurvePoints();if(usingStored){try{points=JSON.parse(option?.dataset.points||'[]')}catch(error){}if(points.length<2)points=defaultPumpCurvePoints()}renderPumpCurvePoints(points,usingStored);if(pumpCurveCode)pumpCurveCode.disabled=usingStored;if(pumpCurveName)pumpCurveName.disabled=usingStored;if(addPumpCurvePoint)addPumpCurvePoint.disabled=usingStored;updatePumpDefinitionFields()};
    const resetPumpCurveEditor=()=>{if(pumpCurveCode){pumpCurveCode.value='';pumpCurveCode.disabled=false}if(pumpCurveName){pumpCurveName.value='';pumpCurveName.disabled=false}if(addPumpCurvePoint)addPumpCurvePoint.disabled=false;renderPumpCurvePoints(defaultPumpCurvePoints())};
    const updatePumpDefinitionFields=()=>{
      const type=linkTypeSelect?.value||'PIPE',mode=pumpDefinitionMode?.value||'HEAD';
      document.querySelectorAll('[data-pump-mode]').forEach(section=>{
        const visible=type==='PUMP'&&activeLinkSection==='hydraulic'&&section.dataset.pumpMode===mode;
        section.hidden=!visible;
        section.querySelectorAll('input,select,button,textarea').forEach(field=>{
          const storedCurveLocked=mode==='HEAD'
            &&!!pumpCurveSelect?.value
            &&(['networkPumpCurveCode','networkPumpCurveName','networkAddPumpCurvePoint'].includes(field.id)
              ||field.matches('[name="pump_curve_flow[]"],[name="pump_curve_head[]"],[data-remove-pump-point]'));
          field.disabled=!visible||storedCurveLocked;
        });
      });
    };
    const setLinkSection=section=>{
      activeLinkSection=section;
      document.querySelectorAll('#networkLinkTabs [data-property-tab]').forEach(button=>button.classList.toggle('active',button.dataset.propertyTab===section));
      toggleLinkTypeFields();
    };
    const toggleLinkTypeFields=()=>{
      const type=linkTypeSelect?.value||'PIPE';
      document.querySelectorAll('[data-link-section]').forEach(section=>{
        const typeVisible=!section.dataset.linkTypes||section.dataset.linkTypes.split(' ').includes(type),sectionVisible=section.dataset.linkSection===activeLinkSection;
        section.hidden=!(typeVisible&&sectionVisible);
        section.querySelectorAll('[required],input,select,textarea').forEach(field=>{
          if(field.dataset.originalRequired===undefined)field.dataset.originalRequired=field.required?'1':'0';
          field.required=typeVisible&&sectionVisible&&field.dataset.originalRequired==='1';
        });
      });
      const manual=document.querySelector('#networkUseManualLength')?.checked!==false,lengthField=document.querySelector('#networkPipeLength');
      if(lengthField)lengthField.readOnly=type==='PIPE'&&!manual;
      updatePumpDefinitionFields();
    };
    document.querySelectorAll('#networkLinkTabs [data-property-tab]').forEach(button=>button.addEventListener('click',()=>setLinkSection(button.dataset.propertyTab)));
    linkTypeSelect?.addEventListener('change',toggleLinkTypeFields);document.querySelector('#networkUseManualLength')?.addEventListener('change',toggleLinkTypeFields);
    pumpDefinitionMode?.addEventListener('change',updatePumpDefinitionFields);pumpCurveSelect?.addEventListener('change',syncPumpCurveEditor);addPumpCurvePoint?.addEventListener('click',()=>{const points=pumpCurveValues(),last=points.at(-1)||{flow_lps:0,head_m:10};renderPumpCurvePoints([...points,{flow_lps:last.flow_lps+10,head_m:Math.max(.1,last.head_m*.7)}])});modalElement?.addEventListener('shown.bs.modal',()=>{updatePumpCurveChart();pumpCurveChart?.resize()});resetPumpCurveEditor();
    const nodeKindSelect=document.querySelector('#networkNodeKind');
    const nodeSectionMap={
      networkNodeCode:'basic',networkNodeName:'basic',networkNodeKind:'basic',networkNodeLinkedKey:'basic',networkNodeStatus:'basic',networkNodeDescription:'basic',
      networkNodeDemand:'demand',networkNodeDemandPatternId:'demand',networkNodeEmitter:'demand',
      networkNodeElevation:'pressure',networkNodeInitialPressure:'pressure',networkNodeMinPressure:'pressure',networkNodeMaxPressure:'pressure',
      networkNodeTotalHead:'pressure',networkNodeHeadPattern:'pressure',networkTankElevation:'pressure',networkTankInitialLevel:'pressure',networkTankMinLevel:'pressure',networkTankMaxLevel:'pressure',networkTankDiameter:'pressure',networkTankMinVolume:'pressure',
      networkNodeInitialQuality:'quality',networkNodeSourceQuality:'quality',networkTankVolumeCurve:'quality',networkTankMixing:'quality',
      networkPumpCurveIdNode:'demand',networkPumpPowerNode:'demand',networkPumpSpeedNode:'demand',networkPumpPatternNode:'demand',
      networkValveType:'demand',networkValveSetting:'demand',networkMeterParameter:'sensor',networkMeterUnit:'sensor'
    };
    Object.entries(nodeSectionMap).forEach(([id,section])=>{const field=document.querySelector(`#${id}`),column=field?.closest('.col-12,.col-md-4,.col-md-6,.col-md-8');if(column&&!column.dataset.nodeSection)column.dataset.nodeSection=section});
    document.querySelectorAll('.node-property-heading').forEach(heading=>heading.dataset.nodeSection='basic');
    let activeNodeSection='basic';
    const setNodeSection=section=>{
      activeNodeSection=section;
      document.querySelectorAll('#networkNodeTabs [data-property-tab]').forEach(button=>button.classList.toggle('active',button.dataset.propertyTab===section));
      document.querySelectorAll('[data-node-section]').forEach(block=>block.hidden=block.dataset.nodeSection!==section);
    };
    document.querySelectorAll('#networkNodeTabs [data-property-tab]').forEach(button=>button.addEventListener('click',()=>setNodeSection(button.dataset.propertyTab)));
    document.querySelector('#networkNodeLinkedKey')?.addEventListener('change',event=>{
      const master=nodeByKey[event.target.value];if(!master||master.type!=='source')return;
      document.querySelector('#networkNodeName').value=master.name||'';
      document.querySelector('#networkNodeElevation').value=master.elevation??'';
      document.querySelector('#networkNodeTotalHead').value=master.elevation??'';
      document.querySelector('#networkSourceHead').value=master.elevation??'';
      document.querySelector('#networkMinimumOperatingFlow').value=master.minimum_flow??'';
      document.querySelector('#networkMaximumWithdrawal').value=master.maximum_flow??'';
    });
    const toggleNodeTypeFields=()=>{
      const kind=nodeKindSelect.value;
      document.querySelectorAll('[data-node-kinds]').forEach(section=>{const visible=section.dataset.nodeKinds.split(' ').includes(kind);section.classList.toggle('d-none',!visible);section.querySelectorAll('input,select,textarea').forEach(field=>field.disabled=!visible)});
      document.querySelectorAll('[data-required-kinds]').forEach(field=>field.required=!field.disabled&&field.dataset.requiredKinds.split(' ').includes(kind));
      setNodeSection(activeNodeSection);
    };
    nodeKindSelect.addEventListener('change',toggleNodeTypeFields);
    const openNode=node=>{
      nodeForm.reset();document.querySelector('#networkNodeMethod').value='';document.querySelector('#networkNodeId').value=node.id;
      const values={networkNodeCode:node.code,networkNodeName:node.name,networkNodeKind:node.node_kind||'junction',networkNodeLinkedKey:node.linked_key||'',networkNodeElevation:node.elevation,networkTankElevation:node.elevation,networkNodeDemand:node.base_demand,networkNodeDemandPattern:node.demand_pattern,networkNodeDemandPatternId:node.demand_pattern_id,networkNodeInitialPressure:node.initial_pressure,networkNodeMinPressure:node.minimum_pressure,networkRequiredPressure:node.required_pressure,networkNodeMaxPressure:node.maximum_pressure,networkPressureExponent:node.pressure_exponent||.5,networkMeasuredPressure:node.measured_pressure,networkPressureMeasuredAt:node.pressure_measured_at?String(node.pressure_measured_at).replace(' ','T').slice(0,16):'',networkDemandCategory:node.demand_category,networkNodeEmitter:node.emitter_coefficient,networkNodeInitialQuality:node.initial_quality,networkNodeSourceQuality:node.source_quality,networkNodeTotalHead:node.total_head,networkNodeHeadPattern:node.head_pattern,networkTankInitialLevel:node.initial_level,networkTankMinLevel:node.minimum_level,networkTankMaxLevel:node.maximum_level,networkTankDiameter:node.tank_diameter,networkTankMinVolume:node.minimum_volume,networkTankVolumeCurve:node.volume_curve,networkTankMixing:node.mixing_model||'mixed',networkHydraulicRepresentation:node.hydraulic_representation,networkSourceHead:node.source_head,networkStaticLevel:node.static_water_level,networkDynamicLevel:node.dynamic_water_level,networkMaximumWithdrawal:node.maximum_withdrawal,networkMinimumOperatingFlow:node.minimum_operating_flow,networkConnectedPump:node.connected_pump_node_id,networkPumpCurveNode:node.pump_curve,networkPumpCurveIdNode:node.pump_curve_id,networkPumpEfficiencyCurve:node.efficiency_curve_id,networkPumpPowerNode:node.pump_power,networkNominalPowerNode:node.nominal_power,networkPumpSpeedNode:node.pump_speed||1,networkPumpPatternNode:node.speed_pattern,networkPumpInlet:node.inlet_node_id,networkPumpOutlet:node.outlet_node_id,networkPumpUnitCount:node.unit_count||1,networkPumpActiveUnitCount:node.active_unit_count??1,networkPumpInitialStatus:node.initial_status||'OPEN',networkPumpControlMode:node.control_mode||'MANUAL',networkPumpSchedule:node.operating_schedule_id,networkValveType:node.valve_type,networkValveSetting:node.valve_setting,networkMeterParameter:node.meter_parameter,networkMeterUnit:node.meter_unit,networkMeterTargetType:node.meter_target_type,networkMeterTargetId:node.meter_target_id,networkMeterSensor:node.meter_sensor_id,networkMeterCurrentValue:node.meter_current_value,networkMeterCalibratedValue:node.meter_calibrated_value,networkMeterCalibrationFactor:node.meter_calibration_factor||1,networkMeterMinimumLimit:node.meter_minimum_limit,networkMeterMaximumLimit:node.meter_maximum_limit,networkMeterMeasuredAt:node.meter_measured_at?String(node.meter_measured_at).replace(' ','T').slice(0,16):'',networkCommunicationStatus:node.communication_status,networkNodeStatus:node.status||'aktif',networkNodeDescription:node.description||''};
      Object.entries(values).forEach(([id,value])=>{const field=document.querySelector(`#${id}`);if(field)field.value=value??''});
      const extraValues={networkSourcePattern:node.source_pattern_id,networkPumpStartLevel:node.start_level,networkPumpStopLevel:node.stop_level,networkPumpStartPressure:node.start_pressure,networkPumpStopPressure:node.stop_pressure};
      Object.entries(extraValues).forEach(([id,value])=>{const field=document.querySelector(`#${id}`);if(field)field.value=value??''});
      document.querySelector('#networkTankOverflow').checked=+node.tank_overflow===1;setNodeSection('basic');toggleNodeTypeFields();document.querySelector('#networkDeleteNode').classList.remove('d-none');nodeModal.show();
    };
    document.querySelector('#networkDeleteNode')?.addEventListener('click',()=>{if(confirm('Hapus titik ini? Semua pipa yang terhubung juga akan diarsipkan.')){document.querySelector('#networkNodeMethod').value='DELETE';nodeForm.submit()}});
    document.querySelectorAll('.network-edit-node').forEach(button=>button.addEventListener('click',()=>{const node=nodeByKey[button.dataset.nodeKey];if(node)openNode(node)}));
    const persistPosition=async node=>{
      try{
        node.x=(node.worldX-worldOriginX)/logicalWidth*100;node.y=(node.worldY-worldOriginY)/logicalHeight*100;
        const body=new URLSearchParams({_token:csrf,project_id:projectId,node_type:node.type,entity_id:node.id,position_x:node.x,position_y:node.y});
        await fetch(networkBoard.dataset.positionUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});
      }catch(error){}
    };
    Object.entries(nodeElements).forEach(([key,element])=>{
      const node=nodeByKey[key];let startX=0,startY=0,pointerId=null;
      element.addEventListener('pointerdown',event=>{
        if(event.button!==0)return;
        if(drawPipeMode){
          event.preventDefault();event.stopPropagation();dragged=true;
          if(!['source','reservoir','node'].includes(node.type)){hint.innerHTML='<i class="bi bi-exclamation-triangle"></i> Titik ini hanya dapat menjadi tujuan pipa.';return}
          drawOrigin=node;drawPointerId=event.pointerId;element.setPointerCapture(event.pointerId);element.classList.add('selected-origin');markTargets(node);updateDrawPreview(node,event);return;
        }
        pointerId=event.pointerId;startX=event.clientX;startY=event.clientY;dragged=false;element.setPointerCapture(pointerId);element.classList.add('dragging');
      });
      element.addEventListener('pointermove',event=>{
        if(drawPipeMode&&drawPointerId===event.pointerId&&drawOrigin){updateDrawPreview(drawOrigin,event);return}
        if(pointerId!==event.pointerId)return;const dx=event.clientX-startX,dy=event.clientY-startY;if(Math.hypot(dx,dy)>4)dragged=true;if(!dragged)return;const point=screenToWorld(event.clientX,event.clientY);node.worldX=point.x;node.worldY=point.y;element.style.left=`${node.worldX}px`;element.style.top=`${node.worldY}px`;drawRoutes();
      });
      element.addEventListener('pointerup',event=>{
        if(drawPipeMode&&drawPointerId===event.pointerId&&drawOrigin){
          const origin=drawOrigin,targetElement=document.elementFromPoint(event.clientX,event.clientY)?.closest('.network-node'),target=targetElement?nodeByKey[targetElement.dataset.nodeKey]:null;
          clearDrawPreview();
          if(isValidRoute(origin,target)){const linkType=drawLinkType;stopDirectDraw();if(linkType==='PUMP'){prepareRoute(origin,target);document.querySelector('#networkLinkType').value='PUMP';document.querySelector('#networkRouteName').value=`Pompa ${origin.name} ke ${target.name}`;document.querySelector('#networkDescription').value='Link pompa EPANET; lengkapi kurva HEAD atau daya POWER.';setLinkSection('hydraulic');toggleLinkTypeFields();modal.show()}else quickCreateRoute(origin,target,'draw')}else{hint.classList.add('active');hint.innerHTML='<i class="bi bi-exclamation-triangle"></i> Lepaskan garis tepat pada titik tujuan yang valid.'}
          return;
        }
        if(pointerId!==event.pointerId)return;element.classList.remove('dragging');pointerId=null;if(dragged)persistPosition(node);
      });
      element.addEventListener('click',event=>{event.stopPropagation();if(dragged){dragged=false;return}chooseNode(node)});
      element.addEventListener('dblclick',event=>{event.preventDefault();event.stopPropagation();if(node.type==='node')openNode(node);else if(node.edit_url)window.location.href=node.edit_url});
    });
    drawRouteButton?.addEventListener('click',()=>toggleDirectDraw('PIPE'));drawPumpButton?.addEventListener('click',()=>toggleDirectDraw('PUMP'));
    const addRouteButton=document.querySelector('#networkAddRoute'),addNodeButton=document.querySelector('#networkAddNode');
    const activateNodeMode=()=>{stopDirectDraw();clearConnection();placingNode=true;sessionStorage.setItem(createModeKey,'node');networkBoard.classList.add('placing-node');addNodeButton?.classList.add('active');hint.classList.add('active');hint.innerHTML='<i class="bi bi-crosshair"></i> Mode titik berulang aktif. Klik lokasi kosong; tekan tombol lagi atau Esc untuk selesai.'};
    const cancelCreateModes=()=>{placingNode=false;networkBoard.classList.remove('placing-node');addNodeButton?.classList.remove('active');stopDirectDraw();clearConnection();sessionStorage.removeItem(createModeKey)};
    addRouteButton?.addEventListener('click',()=>{if(connectionMode){cancelCreateModes()}else startConnection()});
    addNodeButton?.addEventListener('click',()=>{if(placingNode){cancelCreateModes()}else activateNodeMode()});
    networkBoard.addEventListener('click',async event=>{
      if(cameraMoved){cameraMoved=false;return}
      if(placingNode&&!event.target.closest('.network-node')&&!event.target.closest('.network-route-shape')){
        const point=screenToWorld(event.clientX,event.clientY),x=(point.x-worldOriginX)/logicalWidth*100,y=(point.y-worldOriginY)/logicalHeight*100;
        placingNode=false;networkBoard.classList.remove('placing-node');hint.innerHTML='<i class="bi bi-hourglass-split"></i> Membuat titik baru...';
        try{
          const body=new URLSearchParams({_token:csrf,project_id:projectId,position_x:x,position_y:y});
          const response=await fetch(networkBoard.dataset.createNodeUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});
          const payload=await response.json();if(payload.success){sessionStorage.setItem(createModeKey,'node');window.location.reload()}else throw new Error(payload.message);
        }catch(error){hint.innerHTML='<i class="bi bi-exclamation-triangle"></i> Titik belum dapat dibuat. Silakan coba lagi.';activateNodeMode()}
        return;
      }
      if((event.target===networkBoard||event.target.id==='networkNodes')&&connectionMode)startConnection();
    });
    originSelect.addEventListener('change',syncEndpointFields);destinationSelect.addEventListener('change',syncEndpointFields);
    document.querySelectorAll('.network-edit-route').forEach(button=>button.addEventListener('click',()=>openRoute(routeById[button.dataset.routeId])));
    viewport.addEventListener('wheel',event=>{
      event.preventDefault();
      const rect=viewport.getBoundingClientRect(),mouseX=event.clientX-rect.left,mouseY=event.clientY-rect.top,worldX=(mouseX-camera.panX)/camera.scale,worldY=(mouseY-camera.panY)/camera.scale;
      const nextScale=Math.max(.25,Math.min(4,camera.scale*Math.exp(-event.deltaY*.0015)));
      camera.panX=mouseX-worldX*nextScale;camera.panY=mouseY-worldY*nextScale;camera.scale=nextScale;applyCamera();persistCamera();
    },{passive:false});
    let panPointerId=null,panStartX=0,panStartY=0,panOriginX=0,panOriginY=0;
    viewport.addEventListener('pointerdown',event=>{
      if(event.button!==0||placingNode||drawPipeMode||event.target.closest('.network-node,.network-route-shape,button,input,select,textarea'))return;
      panPointerId=event.pointerId;panStartX=event.clientX;panStartY=event.clientY;panOriginX=camera.panX;panOriginY=camera.panY;cameraMoved=false;viewport.setPointerCapture(panPointerId);viewport.classList.add('is-panning');
    });
    viewport.addEventListener('pointermove',event=>{
      if(event.pointerId!==panPointerId)return;
      const dx=event.clientX-panStartX,dy=event.clientY-panStartY;if(Math.hypot(dx,dy)>3)cameraMoved=true;
      camera.panX=panOriginX+dx;camera.panY=panOriginY+dy;applyCamera();
    });
    const finishPan=event=>{if(event.pointerId!==panPointerId)return;panPointerId=null;viewport.classList.remove('is-panning');persistCamera()};
    viewport.addEventListener('pointerup',finishPan);viewport.addEventListener('pointercancel',finishPan);
    document.querySelector('#networkCameraReset')?.addEventListener('click',centerCamera);
    const boardPanel=networkBoard.closest('.network-board-panel');
    document.querySelector('#networkBoardFullscreen')?.addEventListener('click',async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else await boardPanel?.requestFullscreen()}catch(error){}});
    document.addEventListener('fullscreenchange',()=>{boardPanel?.classList.toggle('is-fullscreen',document.fullscreenElement===boardPanel);drawRoutes()});
    const submitKeyboardDelete=(url,fields)=>{
      const deleteForm=document.createElement('form');deleteForm.method='post';deleteForm.action=url;deleteForm.hidden=true;
      Object.entries({_token:csrf,_method:'DELETE',project_id:projectId,...fields}).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=String(value);deleteForm.append(input)});
      document.body.append(deleteForm);deleteForm.submit();
    };
    document.addEventListener('keydown',event=>{
      const typing=event.target instanceof HTMLElement&&!!event.target.closest('input,textarea,select,[contenteditable="true"]');
      if(event.key==='Escape'){
        if(placingNode||connectionMode||drawPipeMode)cancelCreateModes();
        else clearDiagramSelection();
        return;
      }
      if(!['Delete','Backspace'].includes(event.key)||typing||!selectedDiagramObject)return;
      event.preventDefault();
      if(networkBoard.dataset.canDelete!=='1'){hint.classList.add('active');hint.innerHTML='<i class="bi bi-shield-lock"></i> Hanya Super Administrator atau Administrator yang dapat menghapus.';return}
      const {type,data}=selectedDiagramObject;
      if(type==='node'){
        if(data.type!=='node'){hint.classList.add('active');hint.innerHTML='<i class="bi bi-info-circle"></i> Titik master dihapus dari menu data asalnya, bukan dari diagram jaringan.';return}
        if(confirm(`Hapus ${data.name}? Semua pipa yang terhubung juga akan diarsipkan.`))submitKeyboardDelete(networkBoard.dataset.deleteNodeUrl,{node_id:data.id});
      }else if(confirm(`Hapus pipa ${data.route_name}? Pipa akan diarsipkan dari jaringan.`)){
        submitKeyboardDelete(`${networkBoard.dataset.deleteRouteUrl}/${data.id}`,{network_id:data.id});
      }
    });
    let outputPlaybackTimer=null;const stopOutputPlayback=()=>{if(outputPlaybackTimer){clearInterval(outputPlaybackTimer);outputPlaybackTimer=null}if(outputPlay)outputPlay.innerHTML='<i class="bi bi-play-fill"></i>'};
    refreshHydraulicOutput=()=>{
      if(outputTime){
        const selected=outputTime.value;outputTime.innerHTML='<option value="latest">Hasil terkini</option>';
        (hydraulicOutputResults?.periods||[]).forEach(period=>{const option=document.createElement('option');option.value=period.time;option.textContent=`Waktu ${period.time}`;outputTime.append(option)});
        outputTime.value=[...outputTime.options].some(option=>option.value===selected)?selected:'latest';
      }
      if(outputStateElement){
        const available=!!hydraulicOutputResults?.available;
        outputStateElement.className=`network-output-state ${available?'available':''}`;
        outputStateElement.innerHTML=available?`<i class="bi bi-check-circle-fill"></i><span>Output tersedia · ${(hydraulicOutputResults.periods||[]).length} periode hasil.</span>`:'<i class="bi bi-info-circle"></i><span>Jalankan analisis EPANET untuk menghasilkan output.</span>';
      }
      if(outputPlay)outputPlay.disabled=(hydraulicOutputResults?.periods||[]).length<2;
      applyNodeOutput();drawRoutes();
    };
    outputInputs.forEach(input=>input.addEventListener('change',()=>{outputState[input.dataset.networkOutput]=input.checked;localStorage.setItem(projectStorageKey('simma-network-output-layers'),JSON.stringify(outputState));refreshHydraulicOutput()}));
    [[outputFontScale,'fontScale'],[outputLabelScale,'labelScale'],[outputLineScale,'lineScale'],[outputMarkerScale,'markerScale']].forEach(([input,key])=>input?.addEventListener('input',()=>{outputDisplaySettings[key]=+input.value;persistOutputDisplaySettings();applyOutputDisplaySettings()}));
    outputTime?.addEventListener('change',refreshHydraulicOutput);
    outputPlay?.addEventListener('click',()=>{const periods=hydraulicOutputResults?.periods||[];if(periods.length<2)return;if(outputPlaybackTimer){stopOutputPlayback();return}outputPlay.innerHTML='<i class="bi bi-pause-fill"></i>';if(!periods.some(period=>period.time===outputTime?.value)&&outputTime)outputTime.value=periods[0].time;outputPlaybackTimer=setInterval(()=>{const current=Math.max(0,periods.findIndex(period=>period.time===outputTime?.value));const next=(current+1)%periods.length;if(outputTime)outputTime.value=periods[next].time;refreshHydraulicOutput()},850)});
    window.addEventListener('resize',()=>{drawRoutes();applyCamera()});applyOutputDisplaySettings();drawRoutes();refreshHydraulicOutput();if(hasSavedCamera)applyCamera();else requestAnimationFrame(centerCamera);
    const requestedCreateMode=sessionStorage.getItem(createModeKey);
    if(requestedCreateMode==='node')activateNodeMode();else if(requestedCreateMode==='draw')toggleDirectDraw('PIPE');else if(requestedCreateMode==='draw-pump')toggleDirectDraw('PUMP');else if(requestedCreateMode==='connect')startConnection();
  }
  const sensorMonitor=document.querySelector('#waterSensorMonitor');
  if(sensorMonitor){
    const sensorRows=document.querySelector('#waterSensorRows'),syncTime=document.querySelector('#sensorSyncTime');
    const formatNumber=value=>value===null||value===''?'—':new Intl.NumberFormat('id-ID',{minimumFractionDigits:2,maximumFractionDigits:2}).format(+value);
    const renderSensorRows=rows=>{
      sensorRows.innerHTML=rows.length?rows.map(row=>`<tr><td>${escapeHtml(row.recorded_at)}</td><td><strong>${escapeHtml(row.device_name)}</strong><small class="d-block">${escapeHtml(row.device_code)}</small></td><td>${escapeHtml(row.sensor_name)}<small class="d-block">${escapeHtml(row.sensor_code)}</small></td><td><strong>${formatNumber(row.calibrated_value)}</strong> ${escapeHtml(row.unit)}</td><td>${row.battery_voltage===null?'—':escapeHtml(row.battery_voltage)+' V'}</td><td>${row.signal_strength===null?'—':escapeHtml(row.signal_strength)+' dBm'}</td><td><span class="status-badge status-${escapeHtml(row.quality_status)}">${escapeHtml((row.quality_status||'').replaceAll('_',' '))}</span></td></tr>`).join(''):'<tr><td colspan="7" class="text-center text-secondary py-4">Belum ada data sensor yang diterima.</td></tr>';
    };
    setInterval(async()=>{
      try{
        const response=await fetch(sensorMonitor.dataset.syncUrl,{cache:'no-store',headers:{Accept:'application/json'}});
        const payload=await response.json();if(!payload.success)return;
        renderSensorRows(payload.rows||[]);
        if(syncTime)syncTime.textContent=new Date(payload.updated_at.replace(' ','T')).toLocaleString('id-ID');
      }catch(error){}
    },Math.max(5,+sensorMonitor.dataset.refreshSeconds||10)*1000);
  }
  const googleSheetSensors=document.querySelector('[data-google-sheet-sensors]');
  if(googleSheetSensors){
    const tableRows=document.querySelector('#googleSheetSensorRows'),recordCount=document.querySelector('#googleSheetRecordCount'),syncStatus=document.querySelector('#googleSheetSyncStatus');
    const refreshRateSelect=document.querySelector('#googleSheetRefreshRate'),refreshRateKey='simma-google-sheet-refresh-seconds';
    const showValue=value=>value===null||value===undefined||value===''?'—':String(value);
    const renderGoogleSheetRows=rows=>{
      if(!tableRows)return;
      tableRows.replaceChildren();
      if(!rows.length){
        const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=8;td.className='text-center text-secondary py-4';td.textContent='Belum ada data sensor dari Google Sheet.';tr.append(td);tableRows.append(tr);return;
      }
      rows.forEach((row,index)=>{
        const tr=document.createElement('tr');
        [index+1,row.date,row.time,row.temperature,row.ph,row.tds,row.velocity,row.water_level].forEach(value=>{const td=document.createElement('td');td.textContent=showValue(value);tr.append(td)});
        tableRows.append(tr);
      });
    };
    const refreshGoogleSheet=async()=>{
      try{
        const endpoint=new URL(googleSheetSensors.dataset.endpoint,window.location.origin),deviceId=googleSheetSensors.dataset.deviceId;
        if(deviceId&&deviceId!=='0')endpoint.searchParams.set('device_id',deviceId);
        const response=await fetch(endpoint,{cache:'no-store',headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Gagal memuat data');
        const payload=await response.json();renderGoogleSheetRows(payload.rows||[]);
        if(recordCount)recordCount.textContent=`${(payload.rows||[]).length} data ditampilkan`;
        if(syncStatus)syncStatus.innerHTML=`<i class="bi bi-clock-history"></i> Diperbarui ${escapeHtml(payload.updated_at||'-')} · ${Number(payload.device_count)||0} alat terhubung`;
      }catch(error){
        if(syncStatus)syncStatus.innerHTML='<i class="bi bi-exclamation-circle"></i> Sinkronisasi sementara gagal; data terakhir tetap ditampilkan.';
      }
    };
    let sensorRefreshTimer=null;
    const selectedRefreshSeconds=()=>Math.max(5,+(localStorage.getItem(refreshRateKey)||refreshRateSelect?.value||googleSheetSensors.dataset.refreshSeconds||30));
    const scheduleSensorRefresh=()=>{clearInterval(sensorRefreshTimer);sensorRefreshTimer=setInterval(refreshGoogleSheet,selectedRefreshSeconds()*1000)};
    if(refreshRateSelect){refreshRateSelect.value=String(selectedRefreshSeconds());refreshRateSelect.addEventListener('change',()=>{localStorage.setItem(refreshRateKey,refreshRateSelect.value);scheduleSensorRefresh();refreshGoogleSheet()})}
    scheduleSensorRefresh();
  }
  const publicSheetPortal=document.querySelector('[data-public-sheet-refresh]');
  if(publicSheetPortal){
    const refreshRateSelect=document.querySelector('#publicSheetRefreshRate'),refreshRateKey='simma-google-sheet-refresh-seconds';
    let publicRefreshTimer=null,latestAt=publicSheetPortal.dataset.publicSheetLatest||'';
    const refreshSeconds=()=>Math.max(5,+(localStorage.getItem(refreshRateKey)||refreshRateSelect?.value||publicSheetPortal.dataset.publicSheetRefresh||30));
    const checkPublicSheet=async()=>{
      try{
        const endpoint=new URL(publicSheetPortal.dataset.publicSheetStatusUrl,window.location.origin);
        endpoint.searchParams.set('location',publicSheetPortal.dataset.publicLocationId||new URLSearchParams(window.location.search).get('location')||'');
        const response=await fetch(endpoint,{cache:'no-store',headers:{Accept:'application/json'}});
        const payload=await response.json();
        // Halaman tidak dimuat ulang berulang-ulang. Muat ulang hanya sekali jika memang
        // Google Sheet memiliki pembacaan yang lebih baru daripada yang sedang tampil.
        if(payload.success&&payload.latest_at&&latestAt&&payload.latest_at!==latestAt)window.location.reload();
        if(payload.latest_at)latestAt=payload.latest_at;
      }catch(error){}
    };
    const schedulePublicRefresh=()=>{clearInterval(publicRefreshTimer);publicRefreshTimer=setInterval(checkPublicSheet,refreshSeconds()*1000)};
    if(refreshRateSelect){refreshRateSelect.value=String(refreshSeconds());refreshRateSelect.addEventListener('change',()=>{localStorage.setItem(refreshRateKey,refreshRateSelect.value);schedulePublicRefresh();checkPublicSheet()})}
    schedulePublicRefresh();
  }
  const savePublicDashboardJpg=document.querySelector('#savePublicDashboardJpg');
  savePublicDashboardJpg?.addEventListener('click',async()=>{
    if(!window.html2canvas)return;
    const dashboard=document.querySelector('.monitor-portal');if(!dashboard)return;
    const original=savePublicDashboardJpg.innerHTML;
    savePublicDashboardJpg.disabled=true;savePublicDashboardJpg.innerHTML='<span class="spinner-border spinner-border-sm"></span> Menyiapkan JPG…';
    try{
      if(document.fonts?.ready)await document.fonts.ready;
      const scale=Math.min(2,Math.max(1,3840/dashboard.scrollWidth));
      const canvas=await window.html2canvas(dashboard,{backgroundColor:'#eff8f2',scale,useCORS:true,logging:false,windowWidth:dashboard.scrollWidth,windowHeight:dashboard.scrollHeight,ignoreElements:element=>element.classList?.contains('no-export')});
      const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.95));
      if(!blob)throw new Error('JPG tidak dapat dibuat');
      const name=(savePublicDashboardJpg.dataset.locationName||'monitoring-air').toLowerCase().replace(/[^a-z0-9]+/gi,'-').replace(/^-|-$/g,'');
      const link=document.createElement('a');link.href=URL.createObjectURL(blob);link.download=`dashboard-${name}-${new Date().toISOString().slice(0,10)}.jpg`;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(link.href),1000);
    }catch(error){alert('Gagal membuat JPG. Pastikan koneksi peta tersedia lalu coba lagi.');}
    finally{savePublicDashboardJpg.disabled=false;savePublicDashboardJpg.innerHTML=original;}
  });
});
function escapeHtml(value){const d=document.createElement('div');d.textContent=value??'';return d.innerHTML}
function formatWaterNumber(value){return new Intl.NumberFormat('id-ID',{minimumFractionDigits:0,maximumFractionDigits:2}).format(+value||0)}
function addMapBaseLayers(map){
  const streets=L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'});
  const satellite=L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',{maxZoom:19,attribution:'Tiles © Esri'});
  const topography=L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',{maxZoom:17,attribution:'Map data © OpenStreetMap, SRTM | Map style © OpenTopoMap'});
  streets.addTo(map);
  L.control.layers({'Peta Jalan':streets,'Satelit':satellite,'Topografi':topography},null,{position:'topright',collapsed:true}).addTo(map);
  setInterval(()=>{streets.redraw();satellite.redraw();topography.redraw()},300000);
  return {streets,satellite,topography};
}
