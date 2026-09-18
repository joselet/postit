<?php
$tablero = $_GET['tablero'] ?? 'default';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Tablero de notas: <?= htmlspecialchars($tablero) ?></title>
  <style>
    body {
      font-family: sans-serif;
    }

    #toolbar {
      position: fixed;
      top: 10px;
      left: 10px;
      z-index: 1000;
      display: flex;
      gap: 8px;
    }

    #fixOffscreenBtn {
      display: none;
      background: #ffc107;
    }

    .note-window {
      position: absolute;
      top: 100px;
      left: 100px;
      width: 300px;
      height: 200px;
      border: 1px solid #666;
      background: #fff;
      box-shadow: 2px 2px 10px rgba(0,0,0,0.2);
      resize: both;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      z-index: 1;
    }

    .note-title {
      background: #007bff;
      color: white;
      padding: 5px;
      cursor: move;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .note-title .buttons {
      display: flex;
      gap: 5px;
    }

    .note-title-text {
      flex-grow: 1;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      padding-right: 5px;
    }

    .note-title-input {
      flex-grow: 1;
      margin-right: 5px;
      border: none;
      padding: 2px 4px;
      font: inherit;
      color: #000;
    }

    .note-title button {
      background: rgba(255,255,255,0.2);
      border: none;
      color: white;
      cursor: pointer;
      padding: 2px 6px;
    }

    .note-title button:hover {
      background: rgba(255, 255, 255, 0.5);
      /* border: 1px solid white; */
    }

    .note-title button.close {
        background: rgb(255, 0, 0,0.8);
    }
    .note-title button.close:hover {
        background: rgb(255, 0, 0,1);
    }


    .note-content {
      flex-grow: 1;
      padding: 5px;
      overflow: auto;
    }

    textarea {
      width: 100%;
      height: 100%;
      border: none;
      resize: none;
      font-family: inherit;
      font-size: 14px;
    }
    
    #status {
      position: fixed;
      bottom: 10px;
      left: 10px;
      background: #28a745;
      color: white;
      padding: 5px 10px;
      border-radius: 5px;
      display: none;
    }
  </style>
</head>
<body>

  <div id="toolbar">
    <button id="addNoteBtn">➕ Añadir nota</button>
    <button id="fixOffscreenBtn" title="Hay notas fuera de la pantalla visible">📍 Traer notas a la vista</button>
  </div>
  <div id="status">💾 Guardado</div>

  <script>
    const tablero = "<?= htmlspecialchars($tablero) ?>";
    const zIndexBase = 100;
    let zCounter = zIndexBase;

    const statusDiv = document.getElementById('status');
    const showStatus = (msg) => {
      statusDiv.textContent = msg;
      statusDiv.style.display = 'block';
      clearTimeout(statusDiv._timer);
      statusDiv._timer = setTimeout(() => {
        statusDiv.style.display = 'none';
      }, 1500);
    };

    document.getElementById('addNoteBtn').addEventListener('click', () => {
      createNoteWindow('', '', {});
    });

    const fixOffscreenBtn = document.getElementById('fixOffscreenBtn');
    fixOffscreenBtn.addEventListener('click', fixOffscreenNotes);

    // Las notas guardan su posición en coordenadas absolutas de documento (left/top),
    // pensadas para la pantalla donde se crearon. Si luego se abre el tablero en una
    // pantalla más pequeña (u otro ordenador), esas coordenadas pueden caer fuera del
    // viewport actual: la nota sigue "existiendo" (el body hace overflow y se podría
    // llegar a ella haciendo scroll) pero es fácil no darse cuenta y darla por perdida.
    // Por eso comprobamos qué notas quedan completamente fuera del viewport visible y,
    // si hay alguna, mostramos un botón para traerlas todas de vuelta a la vista.
    function isNoteOffscreen(note) {
      const rect = note.getBoundingClientRect();
      return rect.right <= 0 || rect.bottom <= 0
          || rect.left >= window.innerWidth || rect.top >= window.innerHeight;
    }

    function updateOffscreenIndicator() {
      const anyOffscreen = Array.from(document.querySelectorAll('.note-window')).some(isNoteOffscreen);
      fixOffscreenBtn.style.display = anyOffscreen ? 'inline-block' : 'none';
    }

    function fixOffscreenNotes() {
      const margin = 10;
      const viewportWidth = window.innerWidth;
      const viewportHeight = window.innerHeight;
      let offset = 0; // pequeña cascada para que las notas recolocadas no queden todas exactamente apiladas

      document.querySelectorAll('.note-window').forEach(note => {
        if (!isNoteOffscreen(note)) return;

        // si la nota es más grande que la pantalla actual, la reducimos para que quepa entera
        if (note.offsetWidth > viewportWidth) note.style.width = (viewportWidth - margin * 2) + 'px';
        if (note.offsetHeight > viewportHeight) note.style.height = (viewportHeight - margin * 2) + 'px';

        note.style.left = (window.scrollX + margin + offset) + 'px';
        note.style.top = (window.scrollY + margin + offset) + 'px';
        note.style.zIndex = ++zCounter;
        offset += 25;
      });

      saveAllNotes();
      updateOffscreenIndicator();
    }

    // window 'resize' también dispara en cada frame mientras se arrastra el borde de
    // la ventana, igual que el ResizeObserver de las notas; el mismo debounce evita
    // recalcular el indicador decenas de veces por segundo.
    let viewportResizeTimer = null;
    window.addEventListener('resize', () => {
      clearTimeout(viewportResizeTimer);
      viewportResizeTimer = setTimeout(updateOffscreenIndicator, 200);
    });

    // geometry = {left, top, width, height}, cada campo opcional (string CSS, ej. "120px").
    // Si falta left/top se coloca en una posición aleatoria; si falta width/height se deja
    // el tamaño por defecto definido en .note-window (300x200).
    function createNoteWindow(content, title, geometry = {}) {
      const note = document.createElement('div');
      note.classList.add('note-window');
      note.style.top = geometry.top ?? (Math.random() * 200 + 'px');
      note.style.left = geometry.left ?? (Math.random() * 200 + 'px');
      if (geometry.width) note.style.width = geometry.width;
      if (geometry.height) note.style.height = geometry.height;
      note.style.zIndex = ++zCounter;

      note.innerHTML = `
        <div class="note-title">
          <span class="note-title-text" title="Doble clic para renombrar"></span>
          <div class="buttons">
            <button class="close">✖</button>
          </div>
        </div>
        <div class="note-content">
          <textarea></textarea>
        </div>
      `;

      note.querySelector('.note-title-text').textContent = title || '📝 Nota';

      const textarea = note.querySelector('textarea');
      textarea.value = content;

      document.body.appendChild(note);

      note.querySelector('.close').addEventListener('click', () => note.remove());

      // guardar al perder foco
      textarea.addEventListener('blur', saveAllNotes);

      makeDraggable(note, note.querySelector('.note-title'));
      makeTitleEditable(note.querySelector('.note-title-text'));
      observeResize(note);
    }

    // El resize nativo de CSS (`resize: both`) no dispara ningún evento propio
    // mientras el usuario arrastra la esquina, así que usamos ResizeObserver para
    // detectar el cambio de tamaño. Como ResizeObserver notifica en cada frame del
    // arrastre (no solo al soltar), aplicamos un pequeño debounce para guardar una
    // sola vez cuando el usuario deja de mover el ratón, en vez de disparar una
    // petición de guardado por cada pixel.
    function observeResize(note) {
      let resizeTimer = null;
      let isFirstCallback = true; // ResizeObserver siempre notifica una vez al observar,
                                   // aunque no haya habido ningún resize real; la ignoramos
                                   // para no disparar un guardado en cada carga de nota.
      const resizeObserver = new ResizeObserver(() => {
        if (isFirstCallback) {
          isFirstCallback = false;
          return;
        }
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
          saveAllNotes();
          updateOffscreenIndicator();
        }, 300);
      });
      resizeObserver.observe(note);
    }

    function makeTitleEditable(titleEl) {
      titleEl.addEventListener('dblclick', (e) => {
        e.stopPropagation();

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'note-title-input';
        input.value = titleEl.textContent;

        titleEl.replaceWith(input);
        input.focus();
        input.select();

        // evita que un clic dentro del input arrastre la nota
        input.addEventListener('mousedown', ev => ev.stopPropagation());

        const commit = () => {
          titleEl.textContent = input.value.trim() || '📝 Nota';
          input.replaceWith(titleEl);
          saveAllNotes();
        };

        input.addEventListener('blur', commit);
        input.addEventListener('keydown', ev => {
          if (ev.key === 'Enter') {
            ev.preventDefault();
            input.blur();
          } else if (ev.key === 'Escape') {
            input.value = titleEl.textContent;
            input.blur();
          }
        });
      });
    }

    function makeDraggable(el, handle) {
      let offsetX = 0, offsetY = 0, isDown = false;

      handle.addEventListener('mousedown', e => {
        isDown = true;
        offsetX = e.clientX - el.offsetLeft;
        offsetY = e.clientY - el.offsetTop;
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
      });

      function move(e) {
        if (!isDown) return;
        el.style.left = (e.clientX - offsetX) + 'px';
        el.style.top = (e.clientY - offsetY) + 'px';
      }

      function up() {
        isDown = false;
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', up);
        saveAllNotes();
        updateOffscreenIndicator();
      }
    }

    function getAllNotes() {
      return Array.from(document.querySelectorAll('.note-window')).map(note => ({
        text: note.querySelector('textarea').value,
        title: note.querySelector('.note-title-text').textContent,
        // offsetWidth/offsetHeight (no note.style.width/height) porque una nota
        // nunca redimensionada a mano no tiene esas propiedades de style definidas,
        // pero sí ocupa el tamaño por defecto de .note-window (300x200) que
        // también queremos persistir.
        left: note.style.left,
        top: note.style.top,
        width: note.offsetWidth + 'px',
        height: note.offsetHeight + 'px'
      }));
    }

    function saveAllNotes() {
      fetch('api.php?action=save&tablero=' + encodeURIComponent(tablero), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(getAllNotes())
      }).then(res => res.json()).then(data => {
        if (data.success) showStatus("💾 Guardado");
      });
    }

    function loadNotes() {
      fetch('api.php?action=load&tablero=' + encodeURIComponent(tablero))
        .then(res => res.json())
        .then(notes => {
          notes.forEach(note => createNoteWindow(note.text, note.title, {
            left: note.left,
            top: note.top,
            width: note.width,
            height: note.height
          }));
          updateOffscreenIndicator();
        });
    }

    loadNotes();
  </script>
</body>
</html>
