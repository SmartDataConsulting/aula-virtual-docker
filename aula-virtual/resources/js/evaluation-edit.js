import './global.js';
let counter = 0;
let autosaveTimer = null;
let autosavePending = false;
let isLoading = true;
let evaluationEditConfig = {
courseId: 0,
evaluationId: 0,
autosaveUrl: '',
publishUrl: '',
viewUrl: ''
};
let evaluationEditData = {};

console.log("EVALUATION JS loaded");

function calculateTotalScore(){
return Array.from(document.querySelectorAll('.points-input'))
    .reduce((sum, input) => {
        const value = parseFloat(input.value || 0);
        return sum + (Number.isFinite(value) ? value : 0);
    }, 0);
}

function syncScoreDisplay(){
const display = document.getElementById("evaluationScoreDisplay");
if(!display) return;

display.textContent = `${calculateTotalScore()}`;
}


function label(type){
if(type === 'single') return 'Opción única';
if(type === 'boolean') return 'Verdadero/Falso';
if(type === 'multiple') return 'Opción múltiple';
return '';
}

function helperText(type){

if(type==="single"){
return `
<div class="helper-text">
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle size-3">
  <circle cx="12" cy="12" r="10"></circle>
</svg>
Haz clic en el círculo para marcar como correcta
</div>`;
}

if(type==="boolean"){
return `
<div class="helper-text">
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle size-3">
  <circle cx="12" cy="12" r="10"></circle>
</svg>
Haz clic en la opción correcta
</div>`;
}

if(type==="multiple"){
return `
<div class="helper-text">
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-square-check-big size-3">
  <path d="M21 10.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12.5"></path>
  <path d="m9 11 3 3L22 4"></path>
</svg>
Haz clic en el checkbox para marcar como correcta
</div>`;
}

return "";
}

window.addQuestion = function(type){
const id = ++counter;
const container = document.getElementById('questionsContainer');
container.insertAdjacentHTML('beforeend', questionTemplate(type,id));
const card = container.querySelector(`[data-id="${id}"]`);
card.dataset.preguntaId = null;

// ⬇️ AGREGA ESTO
requestAnimationFrame(()=>{
  const card = container.querySelector(`[data-id="${id}"]`);
  card.querySelectorAll("textarea").forEach(t=>{
    t.style.height = "auto";
    t.style.height = t.scrollHeight + "px";
  });
});

// ocultar empty
document.getElementById('emptyState').style.display='none';

// mostrar toolbar inferior
document.getElementById('bottomToolbar').style.display='flex';

syncScoreDisplay();
scheduleAutosave();
refreshPublishButtonState();

}

function questionTemplate(type,id){
return `
<div class="question-card" data-id="${id}" data-type="${type}">

<div class="question-header">
<div class="drag">
<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
<circle cx="9" cy="5" r="1"/>
<circle cx="9" cy="12" r="1"/>
<circle cx="9" cy="19" r="1"/>
<circle cx="15" cy="5" r="1"/>
<circle cx="15" cy="12" r="1"/>
<circle cx="15" cy="19" r="1"/>
</svg>
</div>

<div class="question-type ${type}">
${label(type)}
</div>
</div>

<textarea 
placeholder="Escribe tu pregunta aquí..." 
class="w-full text-lg font-medium resize-none outline-none border-none mb-4 placeholder:text-neutral-400" 
rows="1"
style="min-height: 32px;"
></textarea>

<div class="space-y-2 mb-4">
<div class="helper-text">${helperText(type)}</div>

<div class="options">
${optionsTemplate(type,id)}
</div>

<button 
type="button"
data-add-option-id="${id}"
class="flex items-center gap-2 px-3 py-2 text-sm text-neutral-600 hover:text-neutral-800 transition-colors"
>
<svg xmlns="http://www.w3.org/2000/svg" 
width="24" 
height="24" 
viewBox="0 0 24 24" 
fill="none" 
stroke="currentColor" 
stroke-width="2" 
stroke-linecap="round" 
stroke-linejoin="round" 
class="lucide lucide-plus size-4">
  <path d="M5 12h14"></path>
  <path d="M12 5v14"></path>
</svg>
Agregar opción
</button>
</div>
<div class="mb-4">
<label 
class="items-center gap-2 font-medium select-none text-sm text-neutral-600 mb-2 block"
for="feedback-${id}">
Explicación / Feedback
</label>

<textarea 
id="feedback-${id}"
placeholder="Explica por qué esta es la respuesta correcta. Esto se mostrará al estudiante después de responder..."
class="w-full px-3 py-2 text-sm border border-neutral-200 rounded-lg outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100 resize-none placeholder:text-neutral-400"
rows="3"
></textarea>

</div>

<div class="question-footer">

<div class="points">
<span>Puntos:</span>
<input type="number" min="1" value="1" class="points-input">
</div>

<div class="trash" data-remove-question-id="${id}">
<svg xmlns="http://www.w3.org/2000/svg"
width="18"
height="18"
viewBox="0 0 24 24"
fill="none"
stroke="currentColor"
stroke-width="2"
stroke-linecap="round"
stroke-linejoin="round">
<path d="M3 6h18"></path>
<path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path>
<path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
<line x1="10" x2="10" y1="11" y2="17"></line>
<line x1="14" x2="14" y1="11" y2="17"></line>
</svg>
</div>

</div>

</div>
`
}

function optionsTemplate(type,id){
if(type==="boolean"){
return optionRow(id,type,'Verdadero') +
       optionRow(id,type,'Falso')
}
return optionRow(id,type,'') +
       optionRow(id,type,'')
}

function optionRow(id,type,text){
const input = type==="multiple" ? "checkbox" : "radio";

return `
<div class="option-row ${type}" data-option-row data-option-type="${type}">

<input type="${input}" name="q${id}">
<input class="option-input"
placeholder="Opción"
value="${text}">

<span data-remove-option>&times;</span>

</div>
`
}

window.removeQuestion = function(id){
document.querySelector(`[data-id="${id}"]`).remove();

const total = document.querySelectorAll('.question-card').length;

if(total === 0){
document.getElementById('emptyState').style.display='block';
document.getElementById('bottomToolbar').style.display='none';
}
syncScoreDisplay();
scheduleAutosave();
refreshPublishButtonState();
}

window.addOption = function(id){
const card = document.querySelector(`[data-id="${id}"]`);
const type = card.dataset.type;
card.querySelector('.options')
.insertAdjacentHTML('beforeend', optionRow(id,type,''));
syncScoreDisplay();
scheduleAutosave();
refreshPublishButtonState();
}

window.removeOption = function(e,el){
e.stopPropagation();
el.parentElement.remove();
scheduleAutosave();
refreshPublishButtonState();
}

window.toggleOption = function(row,type){
console.log("toggleOption:", type);
const input = row.querySelector('input');

if(type!=="multiple"){
row.parentElement.querySelectorAll('.option-row').forEach(r=>{
r.classList.remove('selected');
const i = r.querySelector('input');
if(i) i.checked = false;
});
}

row.classList.add('selected');
if(input) input.checked = true;

scheduleAutosave();
refreshPublishButtonState();

}

document.addEventListener("click", function(e){
const addOptionButton = e.target.closest("[data-add-option-id]");
if(addOptionButton){
addOption(addOptionButton.dataset.addOptionId);
return;
}

const removeQuestionButton = e.target.closest("[data-remove-question-id]");
if(removeQuestionButton){
removeQuestion(removeQuestionButton.dataset.removeQuestionId);
return;
}

if(e.target.closest(".option-input")){
return;
}

const removeOptionButton = e.target.closest("[data-remove-option]");
if(removeOptionButton){
removeOption(e, removeOptionButton);
return;
}

const optionRow = e.target.closest("[data-option-row]");
if(optionRow){
toggleOption(optionRow, optionRow.dataset.optionType || "single");
}
});


 document.addEventListener("input", function(e){

console.log("INPUT detectado:", e.target);

// 1. auto resize textarea
if(e.target.tagName === "TEXTAREA"){
requestAnimationFrame(()=>{
  e.target.style.height = "auto";
  e.target.style.height = e.target.scrollHeight + "px";
});
}

// 2. autosave
if(
e.target.matches("textarea") ||
e.target.matches(".option-input") ||
e.target.matches(".points-input") ||
e.target.matches("#approvalScore") ||
e.target.matches("#evaluationWeight") ||
e.target.matches("#timeMinutes")
){
if(e.target.matches(".points-input")){
syncScoreDisplay();
}
scheduleAutosave();
refreshPublishButtonState();
}

});

function collectEvaluationData(){

const questions = [];
const evaluationName = document
    .getElementById("evaluationTitle")
    ?.value
    ?.trim();
const approvalScore = parseFloat(
    document.getElementById("approvalScore")?.value || 0
);

Array.from(document.getElementById('questionsContainer').children)
.forEach((card, index)=>{

const id = card.dataset.id;
const preguntaId = card.dataset.preguntaId || null;
const type = card.dataset.type;

const texto = card.querySelector('textarea').value.trim();
const feedback = card.querySelector(`#feedback-${id}`)?.value || "";
const puntaje = card.querySelector('.points-input')?.value || 1;

const opciones = [];

card.querySelectorAll('.option-row').forEach((row, i)=>{
const input = row.querySelector('.option-input');
const checked = row.querySelector('input')?.checked;

opciones.push({
opcion_id: row.dataset.opcionId 
    ? parseInt(row.dataset.opcionId) 
    : null,
texto: (input?.value || "").trim(),
es_correcta: checked ? 1 : 0,
orden: i + 1
});
});

questions.push({
pregunta_id: preguntaId ? parseInt(preguntaId) : null,
tipo_param_id: mapType(type),
texto,
feedback,
puntaje,
orden: index + 1,
opciones
});

});

console.log("payload generado:", { preguntas: questions });

return {
evaluacion: {
    nombre: evaluationName,
    peso: Number(document.getElementById("evaluationWeight")?.value || 0),
    tiempo_minutos: Number(document.getElementById("timeMinutes")?.value || 0),
    puntaje_aprobacion: approvalScore
},
preguntas: questions
};
}

function mapType(type){
if(type === 'single') return 1;
if(type === 'boolean') return 2;
if(type === 'multiple') return 3;
return 1;
}

function scheduleAutosave(){

if(isLoading){
console.log("autosave bloqueado (loading)");
return;
}

clearTimeout(autosaveTimer);
autosaveTimer = setTimeout(()=>{
performAutosave();
}, 800);
}

function setActionButtonsDisabled(disabled = true){

    const publish = document.getElementById("publishEvaluation");
    const preview = document.getElementById("previewEvaluation");

    if(publish){
        publish.disabled = disabled;
    }

    if(preview){
        preview.style.pointerEvents = disabled ? "none" : "auto";
        preview.style.opacity = disabled ? "0.6" : "1";
    }
}

async function performAutosave(){

if(autosavePending){
console.log("autosave skipped (pending)");
return;
}

autosavePending = true;
setActionButtonsDisabled(true);

console.log("performAutosave START");

showAutosaveStatus("Guardando...");

try{

const payload = collectEvaluationData();

console.log("autosave payload:", payload);

if(!payload.preguntas.length){
    console.log("autosave → enviando vacío (eliminar todo)");
}

console.log("POST →", evaluationEditConfig.autosaveUrl);
console.table(payload.preguntas.map(p => ({
id: p.pregunta_id,
orden: p.orden
})));

const response = await fetch(evaluationEditConfig.autosaveUrl,{
method:"POST",
headers:{
"Content-Type":"application/json",
"X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content
},
body: JSON.stringify(payload)
});

console.log("autosave response status:", response.status);

showAutosaveStatus("Guardado", "success");

}catch(e){

console.error("autosave error", e);
showAutosaveStatus("Error al guardar", "error");

}finally{
autosavePending = false;
setActionButtonsDisabled(false);
refreshPublishButtonState();
console.log("performAutosave END");
}

}

 



function showAutosaveStatus(text, color="neutral"){

const el = document.getElementById("autosaveStatus");
if(!el) return;

el.textContent = text;

el.classList.remove(
"bg-neutral-800",
"bg-green-600",
"bg-red-600"
);

if(color === "success") el.classList.add("bg-green-600");
else if(color === "error") el.classList.add("bg-red-600");
else el.classList.add("bg-neutral-800");

el.style.opacity = "1";

setTimeout(()=>{
el.style.opacity = "0";
}, 2000);

}


function mapTypeReverse(typeId){
if(typeId == 1) return "single";
if(typeId == 2) return "boolean";
if(typeId == 3) return "multiple";
return "single";
}


 document.addEventListener("DOMContentLoaded", function(){

    console.log("INIT evaluation");
    hydrateEvaluationEditState();
    syncDuplicatingOverlay();

    document.querySelectorAll("[data-add-question-type]").forEach((button) => {
        button.addEventListener("click", () => {
            const type = button.dataset.addQuestionType;
            if(type){
                addQuestion(type);
            }
        });
    });

    /* ===================== SORTABLE ===================== */

    const container = document.getElementById('questionsContainer');

  if(container){
      new Sortable(container, {
          animation:150,
          handle:'.drag',
          ghostClass:'dragging',
          onEnd(){
              clearTimeout(autosaveTimer);
              requestAnimationFrame(()=>{
                  requestAnimationFrame(()=>{
                      scheduleAutosave();
                      refreshPublishButtonState();
                  });
              });
          }
      });
  }

    /* ===================== LOAD DATA ===================== */
    if(evaluationEditData){

        const evaluation = evaluationEditData.evaluacion;

        if(evaluation){
            const title = document.getElementById("evaluationTitle");
            if(title){
                title.value = evaluation.nombre || "";
            }
        }

        const preguntas = evaluationEditData.preguntas || [];

        preguntas.forEach(p => {
            const type = mapTypeReverse(p.type_id);
            addQuestion(type);

            const card = document.querySelector('.question-card:last-child');
            const id = card.dataset.id;

            card.dataset.preguntaId = p.id;

            card.querySelector("textarea").value = p.text || "";

            const feedback = card.querySelector(`#feedback-${id}`);
            if(feedback) feedback.value = p.feedback || "";

            const points = card.querySelector(".points-input");
            if(points) points.value = parseInt(p.points || 1);

            card.querySelector(".options").innerHTML = "";

            (p.options || []).forEach(opt => {

                card.querySelector(".options")
                .insertAdjacentHTML("beforeend", optionRow(id,type,opt.text || ""));

                const rows = card.querySelectorAll(".option-row");
                const row = rows[rows.length - 1];

                if(opt.correct){
                    row.classList.add("selected");
                    const input = row.querySelector("input");
                    if(input) input.checked = true;
                }
            });
        });

        const approvalInput = document.getElementById("approvalScore");
        if (approvalInput) {
            approvalInput.value = parseInt(
                evaluation?.pass_score ?? 0
            );
        }

        const weightInput = document.getElementById("evaluationWeight");
        if (weightInput) {
            weightInput.value = Number(evaluation?.weight_percent ?? 0);
        }

        const timeInput = document.getElementById("timeMinutes");
        if (timeInput) {
            timeInput.value = Number(evaluation?.time_minutes ?? 0);
        }

        syncScoreDisplay();
        isLoading = false;
        refreshPublishButtonState();
    }

    /* ===================== PUBLICAR ===================== */
    const btnPublish = document.getElementById("publishEvaluation");

    if(btnPublish){
        btnPublish.addEventListener("click", async function(){

            if(btnPublish.disabled) return;
            btnPublish.disabled = true;

            const ok = await confirmAction({
                title: "Publicar evaluación",
                message: "Una vez publicada no podrás editarla",
                confirmText: "Publicar"
            });

            if(!ok) {
                btnPublish.disabled = false;
                return;
            }
            

            const validation = validateEvaluation();

            if(validation.errors.length){

                showErrorModal(validation.errors.join("\n"));

                if(validation.first){
                    validation.first.scrollIntoView({
                        behavior:"smooth",
                        block:"center"
                    });
                }
                btnPublish.disabled = false;
                return;
            }

            showGlobalLoader("Publicando evaluación...");

            try{

                clearTimeout(autosaveTimer);

                await performAutosave(); // 🔥 uno solo, controlado

                const url = evaluationEditConfig.publishUrl;

                const response = await fetch(url,{
                    method:"POST",
                    headers:{
                        "Content-Type":"application/json",
                        "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const data = await response.json();

                hideGlobalLoader();

                if(!data.ok){
                    showErrorModal(data.error || "No se pudo publicar");
                    btnPublish.disabled = false;
                    return;
                }

                // ÉXITO
                showSuccessModal(
                    `La evaluación "${evaluationEditData.evaluacion.nombre}" se publicó correctamente`
                );

                document.getElementById("appErrorOk").onclick = () => {
                    window.location.replace(evaluationEditConfig.viewUrl);
                };

            }catch(e){

                hideGlobalLoader();
                console.error("publish error", e);
                alert("Error al publicar");
                btnPublish.disabled = false;
            }

        });
    }

    /* ===================== BLOQUEO SI PUBLICADA ===================== */
    const isPublished = evaluationEditData?.evaluacion?.publicada;

    if(isPublished){

        document.querySelectorAll("textarea, input").forEach(el=>{
            el.disabled = true;
        });

        document.querySelectorAll(".btn-type").forEach(btn=>{
            btn.style.display = "none";
        });

        document.querySelectorAll(".trash").forEach(el=>{
            el.style.display = "none";
        });

        const bottom = document.getElementById("bottomToolbar");
        if(bottom) bottom.style.display = "none";
    }

});

function hydrateEvaluationEditState(){
const context = document.getElementById('evaluationEditContext');
const payload = document.getElementById('evaluationEditPayload');

if(!context || !payload){
return;
}

evaluationEditConfig = {
courseId: parseInt(context.dataset.courseId || 0),
evaluationId: parseInt(context.dataset.evaluationId || 0),
autosaveUrl: context.dataset.autosaveUrl || '',
publishUrl: context.dataset.publishUrl || '',
viewUrl: context.dataset.viewUrl || ''
};

try{
evaluationEditData = JSON.parse(payload.innerHTML || '{}');
}catch(error){
console.error("No se pudo leer la configuración de la evaluación", error);
evaluationEditData = {};
}
}

function syncDuplicatingOverlay(){
const context = document.getElementById("evaluationEditContext");
const overlay = document.getElementById("appLoadingOverlay");
const text = document.getElementById("loadingText");

if(context?.dataset.duplicating !== "true" || !overlay){
return;
}

if(text) text.innerText = "Duplicando evaluación...";
overlay.classList.remove("hidden");
overlay.classList.add("flex");

window.addEventListener("load", () => {
setTimeout(() => {
overlay.classList.add("hidden");
overlay.classList.remove("flex");
}, 300);
}, { once: true });
}

 function validateEvaluation(){

    const errors = [];

    document
        .querySelectorAll('.question-card')
        .forEach(card => card.classList.remove("question-error"));

    const cards = document.querySelectorAll('.question-card');

    if(!cards.length){
        return {
            errors:["Debe agregar al menos una pregunta"],
            first:null
        };
    }

    let firstErrorCard = null;

    cards.forEach((card, index) => {

        const nro = index + 1;

        const texto = card.querySelector('textarea')?.value?.trim();
        const feedback = card.querySelector('[id^="feedback-"]')?.value?.trim();
        const puntaje = parseFloat(
            card.querySelector('.points-input')?.value || 0
        );

        const opciones = card.querySelectorAll('.option-row');

        let tieneCorrecta = false;

        opciones.forEach(row=>{
            if(row.querySelector('input')?.checked){
                tieneCorrecta = true;
            }
        });

        let hasError = false;

        if(!texto){
            errors.push(`Pregunta ${nro}: debe tener enunciado`);
            hasError = true;
        }

        if(!feedback){
            errors.push(`Pregunta ${nro}: debe tener feedback`);
            hasError = true;
        }

        if(puntaje <= 0){
            errors.push(`Pregunta ${nro}: el puntaje debe ser mayor a 0`);
            hasError = true;
        }

        if(!tieneCorrecta){
            errors.push(`Pregunta ${nro}: debe marcar una respuesta correcta`);
            hasError = true;
        }

        if(hasError){

            card.classList.add("question-error");

            if(!firstErrorCard){
                firstErrorCard = card;
            }
        }

    });

    const approvalScore = parseInt(document.getElementById("approvalScore")?.value || 0,10);

    if (approvalScore < 1 || approvalScore > 20) {
        errors.unshift("El puntaje mínimo para aprobar debe estar entre 1 y 20");
    }

    return {
        errors,
        first:firstErrorCard
    };
}

function refreshPublishButtonState() {
    const btn = document.getElementById("publishEvaluation");
    if (!btn || btn.disabled && btn.innerText === 'Publicado') return;

    const validation = validateEvaluation();
    const total = calculateTotalScore();

    const valid =
        validation.errors.length === 0 &&
        Math.abs(total - 20) < 0.0001;

    btn.disabled = !valid;

    btn.classList.remove(
        "bg-green-600",
        "hover:bg-green-700",
        "bg-gray-400",
        "cursor-not-allowed"
    );

    if (valid) {
        btn.classList.add("bg-green-600", "hover:bg-green-700");
    } else {
        btn.classList.add("bg-gray-400", "cursor-not-allowed");
    }
}

function showErrorModal(message){

    const modal = document.getElementById("appErrorModal");
    const msg = document.getElementById("appErrorMessage");
    const ok = document.getElementById("appErrorOk");

    msg.innerText = message;

    modal.classList.remove("hidden");
    modal.classList.add("flex");

    ok.onclick = () => {
        modal.classList.add("hidden");
        modal.classList.remove("flex");
    };
}   

