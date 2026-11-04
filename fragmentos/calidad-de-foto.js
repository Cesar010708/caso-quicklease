/* ==========================================================================
   Calidad de la foto en el navegador, antes de subirla (versión genérica)
   --------------------------------------------------------------------------
   Primera de tres capas de control. Mide en milisegundos, sin servidor:
     · Nitidez   — varianza del laplaciano sobre la imagen en gris. Una foto
                   enfocada tiene bordes bruscos (varianza alta); una movida los
                   tiene difuminados y la varianza se desploma.
     · Exposición— brillo medio, para la foto tomada a oscuras o quemada.
     · Tamaño    — lado corto mínimo del original.

   Dos decisiones de diseño:
     1. Umbrales PERMISIVOS. Un falso rechazo cuesta más que un falso aprobado:
        mandar a alguien a repetir una foto que estaba bien es como se abandona
        una solicitud, y una foto regular todavía pasa por dos capas más.
     2. NUNCA bloquea. Avisa y deja seguir. Cualquier error al medir se trata
        como "pasa": esta capa es una cortesía, no un guardián.

   No mide reflejos a propósito: el porcentaje de píxeles casi blancos no
   distingue un reflejo de una credencial blanca. Eso lo resuelve la segunda
   capa (el modelo, que sí sabe dónde está la mancha respecto al texto).
   ========================================================================== */

var UMBRALES = {
  nitidezMinima: 45,     // varianza del laplaciano (gris 0-255)
  brilloMinimo: 42,
  brilloMaximo: 248,     // "toda la imagen es un borrón blanco", no "la hoja es blanca"
  ladoMenorMinimo: 600,  // píxeles del original
};

/**
 * Métricas a partir de la imagen ya convertida a gris (función pura, se prueba
 * sin navegador).
 * @param {Float32Array|number[]} gris  luminancia por píxel, fila por fila
 */
function medir(gris, ancho, alto) {
  var suma = 0;
  for (var i = 0; i < gris.length; i++) suma += gris[i];

  // Laplaciano de 4 vecinos sobre el interior (los bordes del lienzo no son bordes de la imagen).
  var sumaL = 0, sumaL2 = 0, n = 0;
  for (var y = 1; y < alto - 1; y++) {
    for (var x = 1; x < ancho - 1; x++) {
      var c = y * ancho + x;
      var lap = gris[c - 1] + gris[c + 1] + gris[c - ancho] + gris[c + ancho] - 4 * gris[c];
      sumaL += lap;
      sumaL2 += lap * lap;
      n++;
    }
  }
  var media = n ? sumaL / n : 0;
  return {
    nitidez: n ? sumaL2 / n - media * media : 0,
    brillo: gris.length ? suma / gris.length : 0,
  };
}

/** Traduce las métricas a un aviso. El problema más determinante va primero. */
function juzgar(m, ladoMenorOriginal) {
  if (ladoMenorOriginal < UMBRALES.ladoMenorMinimo) return { ok: false, motivo: 'La imagen es muy pequeña: acérquese al documento.' };
  if (m.nitidez < UMBRALES.nitidezMinima)           return { ok: false, motivo: 'La foto salió movida: apoye el teléfono y vuelva a tomarla.' };
  if (m.brillo < UMBRALES.brilloMinimo)             return { ok: false, motivo: 'La foto está muy oscura: busque más luz.' };
  if (m.brillo > UMBRALES.brilloMaximo)             return { ok: false, motivo: 'La foto está sobreexpuesta.' };
  return { ok: true, motivo: '' };
}

/** En el navegador: reduce a 512 px (más no mejora el diagnóstico), pasa a gris y juzga. */
function analizarArchivo(archivo) {
  var pasa = { ok: true, motivo: '' };
  if (!archivo || archivo.type.indexOf('image/') !== 0) return Promise.resolve(pasa); // los PDF no se miden
  return createImageBitmap(archivo).then(function (img) {
    try {
      var escala = Math.min(1, 512 / Math.max(img.width, img.height));
      var w = Math.max(8, Math.round(img.width * escala)), h = Math.max(8, Math.round(img.height * escala));
      var lienzo = document.createElement('canvas');
      lienzo.width = w; lienzo.height = h;
      var ctx = lienzo.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(img, 0, 0, w, h);
      var rgba = ctx.getImageData(0, 0, w, h).data, gris = new Float32Array(w * h);
      for (var i = 0, p = 0; i < rgba.length; i += 4, p++) gris[p] = 0.299 * rgba[i] + 0.587 * rgba[i + 1] + 0.114 * rgba[i + 2];
      return juzgar(medir(gris, w, h), Math.min(img.width, img.height));
    } finally {
      if (img.close) img.close();
    }
  }).catch(function () { return pasa; });
}

// Prueba sin navegador:  node calidad-de-foto.js
if (typeof module !== 'undefined' && typeof require !== 'undefined' && require.main === module) {
  var W = 200, H = 200, nitida = new Float32Array(W * H), movida = new Float32Array(W * H);
  for (var y = 0; y < H; y++) for (var x = 0; x < W; x++) {
    nitida[y * W + x] = ((x >> 3) + (y >> 3)) % 2 ? 230 : 40;   // "texto": bordes duros cada 8 px
    movida[y * W + x] = 135 + 40 * Math.sin(x / 25);           // el mismo contraste, sin bordes
  }
  [['nítida', nitida], ['movida', movida]].forEach(function (par) {
    var m = medir(par[1], W, H), v = juzgar(m, 1200);
    console.log(par[0].padEnd(7), 'nitidez', m.nitidez.toFixed(1).padStart(8), '→', v.ok ? 'pasa' : v.motivo);
  });
  module.exports = { medir: medir, juzgar: juzgar };
}
