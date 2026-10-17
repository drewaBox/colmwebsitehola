// QR code for a request (Stage 5).
//  - QrCode.make(text) builds the QR picture data (a small QR encoder written here, so NO internet / CDN is needed).
//  - showQrCode(container, requestId) asks api/qr.php for the saved QR text (the server creates and saves it the first time)
//    and draws it. If something fails it shows "QR code generation failed. Please try again." with a Try again button.
//
// How the encoder works, in short: text -> bytes -> add error correction (Reed-Solomon) -> put the bits in a grid -> pick the best mask.
// It supports versions 1-10 with error correction level M (enough for about 210 characters).
const QrCode = (() => {
  // version: [error-correction bytes per block, [[number of blocks, data bytes per block], ...]]   (level M)
  const BLOCKS = {
    1: [10, [[1, 16]]], 2: [16, [[1, 28]]], 3: [26, [[1, 44]]], 4: [18, [[2, 32]]], 5: [24, [[2, 43]]],
    6: [16, [[4, 27]]], 7: [18, [[4, 31]]], 8: [22, [[2, 38], [2, 39]]], 9: [22, [[3, 36], [2, 37]]], 10: [26, [[4, 43], [1, 44]]]
  };
  const ALIGN = { 1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34], 7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50] };

  // ---- maths for the error correction (numbers 0-255 with special + and *) ----
  const EXP = new Array(512), LOG = new Array(256);
  (function () {
    let x = 1;
    for (let i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 256) x ^= 0x11d; }
    for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
  })();
  const multiply = (a, b) => (a === 0 || b === 0 ? 0 : EXP[LOG[a] + LOG[b]]);

  function errorCorrection(data, count) {
    let generator = [1];
    for (let i = 0; i < count; i++) {
      const next = new Array(generator.length + 1).fill(0);
      generator.forEach((coef, j) => { next[j] ^= coef; next[j + 1] ^= multiply(coef, EXP[i]); });
      generator = next;
    }
    const rest = new Array(count).fill(0);
    data.forEach(byte => {
      const factor = byte ^ rest.shift();
      rest.push(0);
      generator.slice(1).forEach((coef, j) => { rest[j] ^= multiply(coef, factor); });
    });
    return rest;
  }

  // ---- text -> data bytes (with the length, padding and error correction) ----
  function dataBytesCount(version) { return BLOCKS[version][1].reduce((sum, [n, d]) => sum + n * d, 0); }

  function buildCodewords(bytes, version) {
    const bits = [];
    const put = (value, length) => { for (let i = length - 1; i >= 0; i--) bits.push((value >> i) & 1); };
    put(4, 4);                                  // mode: bytes
    put(bytes.length, version < 10 ? 8 : 16);   // how many bytes
    bytes.forEach(b => put(b, 8));
    const capacity = dataBytesCount(version) * 8;
    for (let i = 0; i < 4 && bits.length < capacity; i++) bits.push(0);
    while (bits.length % 8) bits.push(0);
    for (let pad = 0xec; bits.length < capacity; pad ^= 0xec ^ 0x11) put(pad, 8);
    const data = [];
    for (let i = 0; i < bits.length; i += 8) data.push(parseInt(bits.slice(i, i + 8).join(""), 2));

    // cut into blocks, add error correction to each block, then mix the blocks together
    const [ecCount, groups] = BLOCKS[version];
    const dataBlocks = [];
    let position = 0;
    groups.forEach(([n, size]) => { for (let i = 0; i < n; i++) { dataBlocks.push(data.slice(position, position + size)); position += size; } });
    const ecBlocks = dataBlocks.map(block => errorCorrection(block, ecCount));
    const result = [];
    const longest = Math.max(...dataBlocks.map(b => b.length));
    for (let i = 0; i < longest; i++) dataBlocks.forEach(b => { if (i < b.length) result.push(b[i]); });
    for (let i = 0; i < ecCount; i++) ecBlocks.forEach(b => result.push(b[i]));
    return result;
  }

  // ---- the grid ----
  function makeGrid(version, codewords) {
    const size = version * 4 + 17;
    const dark = Array.from({ length: size }, () => new Array(size).fill(false));
    const fixed = Array.from({ length: size }, () => new Array(size).fill(false));   // true = not a data module
    const set = (x, y, value) => { dark[y][x] = value; fixed[y][x] = true; };        // x = column, y = row

    // finder squares (the three big corners) with the white border around them
    [[0, 0], [size - 7, 0], [0, size - 7]].forEach(([left, top]) => {
      for (let dy = -1; dy <= 7; dy++) for (let dx = -1; dx <= 7; dx++) {
        const x = left + dx, y = top + dy;
        if (x < 0 || y < 0 || x >= size || y >= size) continue;
        const ring = Math.max(Math.abs(dx - 3), Math.abs(dy - 3));
        set(x, y, ring !== 4 && ring !== 2 ? dx >= 0 && dx <= 6 && dy >= 0 && dy <= 6 : false);
      }
    });
    // small alignment squares (not at the three corners where the big squares are)
    const centers = ALIGN[version];
    centers.forEach(cy => centers.forEach(cx => {
      if (fixed[cy][cx]) return;                                  // would touch a finder square
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(cx + dx, cy + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }));
    // timing lines (only where nothing is drawn yet)
    for (let i = 8; i < size - 8; i++) {
      if (!fixed[6][i]) set(i, 6, i % 2 === 0);
      if (!fixed[i][6]) set(6, i, i % 2 === 0);
    }
    // places for the format and version information
    for (let i = 0; i < 9; i++) { if (!fixed[8][i]) set(i, 8, false); if (!fixed[i][8]) set(8, i, false); }
    for (let i = 0; i < 8; i++) { set(size - 1 - i, 8, false); set(8, size - 1 - i, false); }
    set(8, size - 8, true);                                       // the one module that is always dark
    if (version >= 7) for (let i = 0; i < 6; i++) for (let j = 0; j < 3; j++) { set(size - 11 + j, i, false); set(i, size - 11 + j, false); }

    // data bits, in a zig-zag from the bottom-right corner, two columns at a time
    const bits = [];
    codewords.forEach(byte => { for (let i = 7; i >= 0; i--) bits.push((byte >> i) & 1); });
    let index = 0;
    for (let right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;                                 // column 6 is the timing line
      for (let step = 0; step < size; step++) {
        const y = ((right + 1) & 2) === 0 ? size - 1 - step : step;   // going up or down
        for (let k = 0; k < 2; k++) {
          const x = right - k;
          if (fixed[y][x]) continue;
          dark[y][x] = index < bits.length ? bits[index] === 1 : false;
          index++;
        }
      }
    }
    return { size, dark, fixed };
  }

  const MASKS = [
    (x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, (x, y) => x % 3 === 0, (x, y) => (x + y) % 3 === 0,
    (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0, (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
    (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0, (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0
  ];

  function applyMask(grid, mask, version) {
    const { size, fixed } = grid;
    const dark = grid.dark.map(row => row.slice());
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!fixed[y][x] && MASKS[mask](x, y)) dark[y][x] = !dark[y][x];

    const set = (x, y, value) => { dark[y][x] = value; };
    // format information (error correction level M = 0)
    let rest = mask;                                              // data = (0 << 3) | mask
    for (let i = 0; i < 10; i++) rest = (rest << 1) ^ ((rest >>> 9) * 0x537);
    const format = ((mask << 10) | rest) ^ 0x5412;
    const bit = (value, i) => ((value >>> i) & 1) === 1;
    for (let i = 0; i <= 5; i++) set(8, i, bit(format, i));
    set(8, 7, bit(format, 6)); set(8, 8, bit(format, 7)); set(7, 8, bit(format, 8));
    for (let i = 9; i < 15; i++) set(14 - i, 8, bit(format, i));
    for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(format, i));
    for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(format, i));
    set(8, size - 8, true);
    if (version >= 7) {
      let r = version;
      for (let i = 0; i < 12; i++) r = (r << 1) ^ ((r >>> 11) * 0x1f25);
      const info = (version << 12) | r;
      for (let i = 0; i < 18; i++) {
        const a = size - 11 + (i % 3), b = Math.floor(i / 3);
        set(a, b, bit(info, i)); set(b, a, bit(info, i));
      }
    }
    return dark;
  }

  // The mask with the fewest "ugly" patterns is the best one (the standard's four penalty rules).
  function penalty(dark) {
    const size = dark.length;
    let score = 0;
    const lineScore = line => {
      let total = 0, run = 1;
      for (let i = 1; i <= line.length; i++) {
        if (i < line.length && line[i] === line[i - 1]) run++;
        else { if (run >= 5) total += run - 2; run = 1; }
      }
      const text = line.map(v => (v ? "1" : "0")).join("");
      const pattern = /(?:0000)?10111010000?|0000101110100?/g;
      total += 40 * (text.match(pattern) || []).length;
      return total;
    };
    for (let i = 0; i < size; i++) { score += lineScore(dark[i]); score += lineScore(dark.map(row => row[i])); }
    for (let y = 0; y < size - 1; y++) for (let x = 0; x < size - 1; x++) {
      if (dark[y][x] === dark[y][x + 1] && dark[y][x] === dark[y + 1][x] && dark[y][x] === dark[y + 1][x + 1]) score += 3;
    }
    const total = dark.reduce((sum, row) => sum + row.filter(Boolean).length, 0);
    score += Math.floor(Math.abs(total * 20 - size * size * 10) / (size * size)) * 10;
    return score;
  }

  // Returns the picture as a list of rows of true (dark) / false (light). Throws an Error if the text is too long.
  function make(text) {
    const bytes = Array.from(new TextEncoder().encode(String(text)));
    let version = 1;
    while (version <= 10 && bytes.length + (version < 10 ? 2 : 3) > dataBytesCount(version)) version++;
    if (version > 10) throw new Error("The text is too long for the QR code.");
    const grid = makeGrid(version, buildCodewords(bytes, version));
    let best = null, bestScore = Infinity;
    for (let mask = 0; mask < 8; mask++) {
      const candidate = applyMask(grid, mask, version);
      const score = penalty(candidate);
      if (score < bestScore) { bestScore = score; best = candidate; }
    }
    return best;
  }

  // Draws the QR picture on a <canvas>.
  function draw(canvas, text, moduleSize = 5) {
    const dark = make(text);
    const border = 4;
    const size = dark.length + border * 2;
    canvas.width = canvas.height = size * moduleSize;
    const context = canvas.getContext("2d");
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = "#123c2d";
    dark.forEach((row, y) => row.forEach((value, x) => { if (value) context.fillRect((x + border) * moduleSize, (y + border) * moduleSize, moduleSize, moduleSize); }));
  }

  return { make, draw };
})();

// Shows the QR code of a request inside `container`. The server saves the QR the first time, later calls return the same one.
// Returns the canvas on success (the caller may download it), or null if it failed.
async function showQrCode(container, requestId) {
  container.replaceChildren();
  const status = document.createElement("p");
  status.className = "qr-status";
  status.textContent = "Preparing your QR code...";
  container.append(status);
  try {
    const data = await Api.get("qr.php", { request_id: requestId });
    const canvas = document.createElement("canvas");
    canvas.className = "qr-canvas";
    canvas.setAttribute("aria-label", "QR code of request " + data.tracking_no);
    QrCode.draw(canvas, data.qr_text);
    container.replaceChildren(canvas);
    return canvas;
  } catch (error) {
    // Failed: say so and let the person try again (no page refresh needed).
    const message = document.createElement("p");
    message.className = "qr-status qr-failed";
    message.textContent = "QR code generation failed. Please try again.";
    const retry = document.createElement("button");
    retry.type = "button";
    retry.className = "request-back-btn";
    retry.textContent = "Try again";
    retry.addEventListener("click", () => showQrCode(container, requestId));
    container.replaceChildren(message, retry);
    return null;
  }
}
