(function () {
  const MODULE_INFO = {
    "svg-upload": {
      level: "safe",
      title: "Subir SVG",
      what: "Permite subir SVG con sanitizacion durante el upload.",
      use: "Sirve para trabajar con logos e iconos vectoriales.",
      risks: "Un SVG no sanitizado puede incluir codigo malicioso.",
      recommendation: "Mantener activo solo con sanitizacion.",
      when: "Cuando el sitio usa assets SVG confiables."
    },
    "image-delivery": {
      level: "safe",
      title: "Entrega de imagenes",
      what: "Ajusta atributos de carga en imagenes renderizadas por WordPress.",
      use: "Mejora la entrega frontend sin modificar archivos ni contenido.",
      risks: "Una mala seleccion de LCP puede afectar performance.",
      recommendation: "Mantener activo y validar la primera imagen visible.",
      when: "En sitios con imagenes de contenido renderizadas por WordPress."
    },
    "clean-head": {
      level: "safe",
      title: "Limpieza Head",
      what: "Quita metadatos y recursos no criticos del head.",
      use: "Reduce ruido tecnico sin tocar REST API.",
      risks: "Quitar recursos equivocados puede afectar integraciones.",
      recommendation: "Usar con las opciones seguras por defecto.",
      when: "Como higiene base en sitios de produccion."
    },
    "security": {
      level: "safe",
      title: "Seguridad",
      what: "Agrega limite de login, XML-RPC configurable, headers seguros y hooks de integracion.",
      use: "Crea una base de seguridad reversible sin tocar sesiones ni REST API.",
      risks: "Rate limits agresivos o XML-RPC bloqueado pueden afectar integraciones.",
      recommendation: "Mantener activo el baseline seguro y probar opciones avanzadas antes de produccion.",
      when: "En la mayoria de sitios corporativos y operativos."
    },
    "script-optimization": {
      level: "warning",
      title: "Scripts defer",
      what: "Retrasa la ejecucion de scripts no criticos permitidos.",
      use: "Puede mejorar performance si los handles estan validados.",
      risks: "Puede romper menus, formularios o dependencias JS.",
      recommendation: "Mantener desactivado salvo testing controlado.",
      when: "Solo en sitios validados sin errores JS."
    },
    "child-theme": {
      level: "safe",
      title: "Child Theme",
      what: "Genera un child theme minimo para el tema activo.",
      use: "Permite conservar personalizaciones sin modificar el tema padre.",
      risks: "Activarlo cambia el theme activo y debe validarse visualmente.",
      recommendation: "Crear primero sin activar; activar solo despues de revisar.",
      when: "Antes de agregar ajustes propios al tema."
    },
    "upload-limits": {
      level: "warning",
      title: "Limites de subida",
      what: "Ajusta el limite maximo de subida reportado por WordPress.",
      use: "Ayuda cuando el servidor permite archivos mayores.",
      risks: "No puede superar limites reales de hosting o PHP.",
      recommendation: "Validar el limite del servidor antes de activar.",
      when: "Solo si el hosting ya permite el tamano requerido."
    },
    "maintenance-mode": {
      level: "warning",
      title: "Modo mantenimiento",
      what: "Muestra una pantalla 503 a visitantes no autorizados.",
      use: "Protege despliegues o cambios en curso.",
      risks: "Puede ocultar el sitio si se activa accidentalmente.",
      recommendation: "Usar por ventanas cortas y validar IP publica.",
      when: "Durante mantenimiento planificado."
    },
    "redirects": {
      level: "warning",
      title: "Redirecciones",
      what: "Gestiona redirecciones 301 y 302 desde el admin.",
      use: "Ordena cambios de URLs sin tocar servidor.",
      risks: "Loops o cadenas pueden afectar SEO y navegacion.",
      recommendation: "Usar rutas concretas y evitar cadenas.",
      when: "Al retirar o consolidar paginas existentes."
    },
    "login-personalizer": {
      level: "safe",
      title: "Login Personalizer",
      what: "Personaliza logo, fondo y textos del login.",
      use: "Da coherencia visual al acceso del sitio.",
      risks: "Cambios visuales excesivos pueden afectar legibilidad.",
      recommendation: "Usar estilos sobrios y probar en mobile.",
      when: "Cuando el login debe reflejar la marca."
    },
    "snippets": {
      level: "danger",
      title: "Snippets",
      what: "Inserta codigo en head, body o footer.",
      use: "Permite integraciones puntuales sin tocar el tema.",
      risks: "Codigo incorrecto puede romper frontend o tracking.",
      recommendation: "Usar solo codigo auditado y reversible.",
      when: "Para integraciones pequenas y verificadas."
    }
  };

  const labels = {
    safe: { text: "Seguro", icon: "🟢", className: "estandar-safe" },
    warning: { text: "Cuidado", icon: "🟡", className: "estandar-warning" },
    danger: { text: "Riesgoso", icon: "🔴", className: "estandar-danger" }
  };

  function getInfo(moduleKey) {
    return MODULE_INFO[moduleKey] || null;
  }

  function renderRiskBadge(info) {
    const meta = labels[info.level] || labels.warning;
    return `<span class="estandar-badge ${meta.className}">${meta.icon} ${meta.text}</span>`;
  }

  function renderInfoBox(moduleKey) {
    const info = getInfo(moduleKey);
    if (!info) return "";

    return `
      <div class="estandar-module-info" data-module="${moduleKey}">
        <button class="estandar-module-info-header" type="button" aria-expanded="false">
          <span>Informacion del modulo: ${info.title}</span>
          ${renderRiskBadge(info)}
        </button>
        <div class="estandar-module-info-content">
          <p><strong>Que hace:</strong> ${info.what}</p>
          <p><strong>Para que sirve:</strong> ${info.use}</p>
          <p><strong>Riesgos:</strong> ${info.risks}</p>
          <p><strong>Recomendacion:</strong> ${info.recommendation}</p>
          <p><strong>Cuando usarlo:</strong> ${info.when}</p>
        </div>
      </div>`;
  }

  function mountInfoBoxes() {
    document.querySelectorAll(".estandar-admin .estandar-module").forEach(function (el) {
      const key = el.dataset.module;
      const mount = el.querySelector(".estandar-module-info-mount");
      if (mount) mount.innerHTML = renderInfoBox(key);
    });
  }

  function mountRiskBadges() {
    document.querySelectorAll(".estandar-admin .estandar-module-row").forEach(function (row) {
      const info = getInfo(row.dataset.module);
      const mount = row.querySelector(".estandar-module-risk-mount");
      if (info && mount) mount.innerHTML = renderRiskBadge(info);
    });
  }

  function restoreInfoState() {
    document.querySelectorAll(".estandar-admin .estandar-module-info").forEach(function (box) {
      const key = box.dataset.module;
      const open = localStorage.getItem("ko_info_" + key) === "1";
      const header = box.querySelector(".estandar-module-info-header");
      box.classList.toggle("is-open", open);
      if (header) header.setAttribute("aria-expanded", open ? "true" : "false");
    });
  }

  document.addEventListener("click", function (event) {
    if (!event.target || !event.target.closest) return;

    const header = event.target.closest(".estandar-admin .estandar-module-info-header");
    if (!header) return;

    const box = header.closest(".estandar-module-info");
    if (!box) return;

    const open = box.classList.toggle("is-open");
    header.setAttribute("aria-expanded", open ? "true" : "false");
    localStorage.setItem("ko_info_" + box.dataset.module, open ? "1" : "0");
  });

  document.addEventListener("DOMContentLoaded", function () {
    mountInfoBoxes();
    mountRiskBadges();
    restoreInfoState();
  });
})();
