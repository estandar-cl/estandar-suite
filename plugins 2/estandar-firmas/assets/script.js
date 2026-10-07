/**
 * script.js
 * Lógica del generador de firmas institucionales: vista previa, GIFs, imagen personalizada, copia y descarga.
 */

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("signatureForm");
  const previewContainer = document.getElementById("signaturePreview");
  const signatureHTML = document.getElementById("signatureHTML");
  const gifGallery = document.getElementById("gifGallery");
  const verMasBtn = document.getElementById("verMasGifs");
  const copyBtn = document.getElementById("copyHTML");
  const downloadBtn = document.getElementById("downloadSignature");
  const customImage = document.getElementById("customImage");

  const generatorEl = document.querySelector("#signature-generator");
  const companyKey = generatorEl?.dataset.company || "";
  const companyColor = generatorEl?.dataset.color || "#44655d";
  const companySite = generatorEl?.dataset.site || "#";
  const companyDomain = generatorEl?.dataset.domain || "@empresa.cl";
  const companyAddress = generatorEl?.dataset.address || "";
  const pluginUrl = ESTANDAR?.pluginUrl || "";

  let selectedGif = "";
  let customImageData = "";
  let allGifsVisible = false;

  // === Cargar GIFs ===
function loadGifs() {
  gifGallery.innerHTML = "<p>Cargando imágenes...</p>";
  fetch(`${ESTANDAR.ajaxurl}?action=get_company_gifs&company=${companyKey}`)
    .then((res) => res.json())
    .then((data) => {
      gifGallery.innerHTML = "";

      const files = data?.data?.files || [];
      const defaultGif = `${pluginUrl}assets/gifs/${companyKey}/${companyKey}_default.gif`;

      // Si no hay imágenes, mostramos la por defecto
      if (!files.length) {
        const img = document.createElement("img");
        img.src = defaultGif;
        img.className = "gif-thumb selected";
        selectedGif = defaultGif;
        gifGallery.appendChild(img);
        return;
      }

      // Si hay imágenes, añadimos la por defecto al inicio
      const defaultImg = document.createElement("img");
      defaultImg.src = defaultGif;
      defaultImg.className = "gif-thumb selected";
      defaultImg.addEventListener("click", () => {
        document.querySelectorAll(".gif-thumb").forEach((e) => e.classList.remove("selected"));
        defaultImg.classList.add("selected");
        selectedGif = defaultGif;
      });
      gifGallery.appendChild(defaultImg);

      // Mostrar resto de GIFs evitando duplicado del default
      files.forEach((url) => {
        if (url.includes(`${companyKey}_default.gif`)) return;

        const img = document.createElement("img");
        img.src = url;
        img.className = "gif-thumb";
        img.addEventListener("click", () => {
          document.querySelectorAll(".gif-thumb").forEach((e) => e.classList.remove("selected"));
          img.classList.add("selected");
          selectedGif = url;
        });
        gifGallery.appendChild(img);
      });
    })
    .catch((err) => {
      console.error("Error al cargar GIFs:", err);
      gifGallery.innerHTML = "<p>Error al cargar GIFs.</p>";
    });
}

// Ejecutar carga inicial de GIFs y aplicar color institucional
document.documentElement.style.setProperty("--firma-color", companyColor);
loadGifs();

  // === Validar imagen personalizada ===
  if (customImage) {
    customImage.addEventListener("change", (e) => {
      const file = e.target.files[0];
      if (!file) return;

      const allowed = ["image/gif", "image/jpeg", "image/png"];
      if (!allowed.includes(file.type)) {
        alert("Solo se permiten archivos GIF o JPG/PNG.");
        return;
      }

      if (file.size > 200 * 1024) {
        alert("El archivo excede los 200 KB recomendados.");
      }

      const reader = new FileReader();
      const img = new Image();
      const canvas = document.getElementById("imageCanvas");
      const ctx = canvas.getContext("2d");
      const wrapper = document.getElementById("imagePreviewWrapper");
      const warning = document.getElementById("imageWarning");

      reader.onload = (event) => {
        img.onload = () => {
          wrapper.style.display = "block";
          ctx.clearRect(0, 0, canvas.width, canvas.height);

          const ratio = Math.min(canvas.width / img.width, canvas.height / img.height);
          const newWidth = img.width * ratio;
          const newHeight = img.height * ratio;
          const offsetX = (canvas.width - newWidth) / 2;
          const offsetY = (canvas.height - newHeight) / 2;

          ctx.drawImage(img, offsetX, offsetY, newWidth, newHeight);

          if (img.width !== 114 || img.height !== 114) {
            warning.innerText = "⚠️ Tu imagen se ajustará automáticamente al formato de la firma (114×114 px).";
          } else {
            warning.innerText = "";
          }

          customImageData = canvas.toDataURL();
          selectedGif = customImageData;
        };
        img.src = event.target.result;
      };
      reader.readAsDataURL(file);
    });
  }

  // === Generar firma ===
  document.getElementById("generateSignature").addEventListener("click", () => {
    const nombre = document.getElementById("nombre").value.trim();
    const cargo = document.getElementById("cargo").value.trim();
    const correoLocal = document.getElementById("correo_local").value.trim();
    const telefono = document.getElementById("telefono").value.trim();
    const telefono2 = document.getElementById("telefono2").value.trim();

    if (!nombre || !cargo || !correoLocal) {
      alert("Por favor completa los campos obligatorios.");
      return;
    }

    const correoCompleto = `${correoLocal}${companyDomain}`;
	const imageUrl =
	  selectedGif ||
	  `${pluginUrl}assets/gifs/${companyKey}/${companyKey}_default.gif`;
      const direccionFormateada = companyAddress.replace(/\n/g, "<br>");
    const siteVisible = companySite.replace(/^https?:\/\//, "");

    const html = `
<table style="font-family: Arial, sans-serif; font-size:14px; color:#3c3c3b; line-height:1.4; border-collapse:collapse;">
  <tr>
    <td style="padding:0; margin:0; vertical-align:top; border-collapse:collapse;">
      <img src="${imageUrl}"
           alt="${companyKey}"
           width="114" height="114"
           style="display:block; border:none; margin:0; padding:0; line-height:0;">
    </td>
    <td style="padding:0 10px; margin:0; vertical-align:middle; line-height:1.35;">
      <strong style="font-size:16px; color:#000;">${nombre}</strong><br>
      <span style="color:#444;">${cargo}</span><br>
      <div style="height:5px; line-height:5px;">&nbsp;</div>
      <a href="mailto:${correoCompleto}" style="color:${companyColor}; text-decoration:none;">${correoCompleto}</a><br>
      ${telefono ? `<span style='color:#444;'>${telefono}</span><br>` : ""}
      ${telefono2 ? `<span style='color:#444;'>${telefono2}</span><br>` : ""}
      <a href="${companySite}" style="color:${companyColor}; text-decoration:none;">${siteVisible}</a>
    </td>
  </tr>
  <tr>
    <td colspan="2" style="padding-top:9px;">
      <div style="font-size:12px; color:${companyColor}; line-height:1.25; text-align:left;">
        ${direccionFormateada}
      </div>
    </td>
  </tr>
</table>
`.trim();

// === Mostrar firma en un iframe aislado ===
previewContainer.style.display = "block";
signatureHTML.innerHTML = ""; // Limpia contenido previo

const iframe = document.createElement("iframe");
iframe.style.width = "100%";
iframe.style.height = "280px";
iframe.style.border = "1px solid #ccc";
iframe.style.borderRadius = "6px";
iframe.style.background = "#fff";
signatureHTML.appendChild(iframe);

const doc = iframe.contentDocument || iframe.contentWindow.document;
doc.open();
doc.write(`
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body { margin: 0; padding: 20px; font-family: Arial, sans-serif; background: #fff; }
table { border-collapse: collapse; margin: auto; }
</style>
</head>
<body>
${html}
</body>
</html>
`);
doc.close();

// === Copiar firma renderizada (no HTML) ===
if (copyBtn) {
  copyBtn.onclick = () => {
    const iframe = signatureHTML.querySelector("iframe");
    if (!iframe) return;

    const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
    const range = iframeDoc.createRange();
    range.selectNodeContents(iframeDoc.body);

    const selection = iframeDoc.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    iframeDoc.execCommand("copy");

    copyBtn.innerHTML = "✅ Copiada";
    copyBtn.style.backgroundColor = "#3c7a58";
    setTimeout(() => {
      copyBtn.innerHTML = "📋 Copiar";
      copyBtn.style.backgroundColor = "";
    }, 1800);
  };
}

    // === Descargar firma ===
    if (downloadBtn) {
      downloadBtn.onclick = () => {
        const blob = new Blob([html], { type: "text/html" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = "firma.html";
        a.click();
        URL.revokeObjectURL(url);
      };
    }
  });
});
