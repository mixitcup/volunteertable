/*
 * Minimal .xlsx writer (no dependencies).
 *
 *   var bytes = Xlsx.build([
 *     { name: 'Sheet', columns: [{ title: 'Name', width: 20 }, { title: 'Start', type: 'date' }],
 *       rows: [['Anna', new Date()]] }
 *   ]);
 *
 * Cell values: strings, numbers, Date objects (shown as date and time) or null.
 * Strings are stored as text, never as formulas, so values like "=1+1" stay text.
 * The result is a Uint8Array with the contents of an .xlsx file.
 */
var Xlsx = (function() {
  'use strict';

  var encoder = new TextEncoder();

  function escapeXml(value) {
    return String(value)
      // Control characters other than tab and line breaks are not allowed in XML.
      .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F￾￿]/g, '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // Column letters: 0 -> A, 25 -> Z, 26 -> AA.
  function columnName(index) {
    var name = '';
    for (index += 1; index > 0; index = Math.floor((index - 1) / 26)) {
      name = String.fromCharCode(65 + (index - 1) % 26) + name;
    }
    return name;
  }

  // Excel stores dates as days since 1899-12-30, in local time (no time zone).
  function excelDate(date) {
    var local = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate(),
      date.getHours(), date.getMinutes(), date.getSeconds());
    return (local - Date.UTC(1899, 11, 30)) / 86400000;
  }

  // Styles: 0 = default, 1 = bold header, 2 = date and time, 3 = wrapped text.
  var STYLE_HEADER = 1;
  var STYLE_DATE = 2;
  var STYLE_WRAP = 3;

  function cellXml(ref, value) {
    if (value === null || value === undefined || value === '') {
      return '';
    }
    if (value instanceof Date) {
      return '<c r="' + ref + '" s="' + STYLE_DATE + '"><v>' + excelDate(value) + '</v></c>';
    }
    if (typeof value === 'number' && isFinite(value)) {
      return '<c r="' + ref + '"><v>' + value + '</v></c>';
    }
    var text = String(value);
    var style = text.indexOf('\n') !== -1 ? ' s="' + STYLE_WRAP + '"' : '';
    return '<c r="' + ref + '" t="inlineStr"' + style + '><is><t xml:space="preserve">' +
      escapeXml(text) + '</t></is></c>';
  }

  function sheetXml(sheet) {
    var lastColumn = columnName(sheet.columns.length - 1);
    var rows = [sheet.columns.map(function(column) { return column.title; })].concat(sheet.rows);

    var xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
      // Keep the header row visible while scrolling.
      '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' +
      '</sheetView></sheetViews>' +
      '<cols>' + sheet.columns.map(function(column, index) {
        return '<col min="' + (index + 1) + '" max="' + (index + 1) + '" width="' + (column.width || 18) + '" customWidth="1"/>';
      }).join('') + '</cols><sheetData>';

    rows.forEach(function(row, rowIndex) {
      xml += '<row r="' + (rowIndex + 1) + '">';
      row.forEach(function(value, columnIndex) {
        var ref = columnName(columnIndex) + (rowIndex + 1);
        if (rowIndex === 0) {
          xml += '<c r="' + ref + '" t="inlineStr" s="' + STYLE_HEADER + '"><is><t>' + escapeXml(value) + '</t></is></c>';
        } else {
          xml += cellXml(ref, value);
        }
      });
      xml += '</row>';
    });

    // Filter buttons in the header row.
    return xml + '</sheetData><autoFilter ref="A1:' + lastColumn + rows.length + '"/></worksheet>';
  }

  function workbookFiles(sheets) {
    var files = {};
    files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
      '<Default Extension="xml" ContentType="application/xml"/>' +
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
      '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' +
      sheets.map(function(sheet, index) {
        return '<Override PartName="/xl/worksheets/sheet' + (index + 1) + '.xml" ' +
          'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
      }).join('') + '</Types>';

    files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
      '</Relationships>';

    files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' +
      'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' +
      sheets.map(function(sheet, index) {
        return '<sheet name="' + escapeXml(sheet.name) + '" sheetId="' + (index + 1) + '" r:id="rId' + (index + 1) + '"/>';
      }).join('') + '</sheets>' +
      // The filter range of each sheet, needed for Excel to show the filter buttons.
      '<definedNames>' + sheets.map(function(sheet, index) {
        return '<definedName name="_xlnm._FilterDatabase" localSheetId="' + index + '" hidden="1">\'' +
          escapeXml(sheet.name.replace(/'/g, '\'\'')) + '\'!$A$1:$' + columnName(sheet.columns.length - 1) +
          '$' + (sheet.rows.length + 1) + '</definedName>';
      }).join('') + '</definedNames></workbook>';

    files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
      sheets.map(function(sheet, index) {
        return '<Relationship Id="rId' + (index + 1) + '" ' +
          'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" ' +
          'Target="worksheets/sheet' + (index + 1) + '.xml"/>';
      }).join('') +
      '<Relationship Id="rId' + (sheets.length + 1) + '" ' +
      'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' +
      '</Relationships>';

    files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
      '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd hh:mm"/></numFmts>' +
      '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>' +
      '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' +
      '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' +
      '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' +
      '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' +
      '<cellXfs count="4">' +
      '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' +
      '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' +
      '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' +
      '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>' +
      '</cellXfs>' +
      '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' +
      '</styleSheet>';

    sheets.forEach(function(sheet, index) {
      files['xl/worksheets/sheet' + (index + 1) + '.xml'] = sheetXml(sheet);
    });
    return files;
  }

  /* Zip container (entries stored uncompressed, which every Excel version reads). */

  var crcTable = (function() {
    var table = new Uint32Array(256);
    for (var n = 0; n < 256; n++) {
      var c = n;
      for (var k = 0; k < 8; k++) {
        c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
      }
      table[n] = c >>> 0;
    }
    return table;
  })();

  function crc32(bytes) {
    var crc = 0xFFFFFFFF;
    for (var i = 0; i < bytes.length; i++) {
      crc = crcTable[(crc ^ bytes[i]) & 0xFF] ^ (crc >>> 8);
    }
    return (crc ^ 0xFFFFFFFF) >>> 0;
  }

  function zip(files) {
    var parts = [];
    var central = [];
    var offset = 0;
    var DOS_DATE = (0 << 9) | (1 << 5) | 1; // 1980-01-01

    Object.keys(files).forEach(function(name) {
      var nameBytes = encoder.encode(name);
      var data = encoder.encode(files[name]);
      var crc = crc32(data);

      var local = new DataView(new ArrayBuffer(30));
      local.setUint32(0, 0x04034B50, true);   // local file header signature
      local.setUint16(4, 20, true);           // version needed
      local.setUint16(6, 0x0800, true);       // flags: UTF-8 names
      local.setUint16(8, 0, true);            // method: stored
      local.setUint16(10, 0, true);           // time
      local.setUint16(12, DOS_DATE, true);    // date
      local.setUint32(14, crc, true);
      local.setUint32(18, data.length, true); // compressed size
      local.setUint32(22, data.length, true); // uncompressed size
      local.setUint16(26, nameBytes.length, true);
      local.setUint16(28, 0, true);           // extra field length
      parts.push(new Uint8Array(local.buffer), nameBytes, data);

      var entry = new DataView(new ArrayBuffer(46));
      entry.setUint32(0, 0x02014B50, true);   // central directory signature
      entry.setUint16(4, 20, true);           // version made by
      entry.setUint16(6, 20, true);           // version needed
      entry.setUint16(8, 0x0800, true);
      entry.setUint16(10, 0, true);
      entry.setUint16(12, 0, true);
      entry.setUint16(14, DOS_DATE, true);
      entry.setUint32(16, crc, true);
      entry.setUint32(20, data.length, true);
      entry.setUint32(24, data.length, true);
      entry.setUint16(28, nameBytes.length, true);
      entry.setUint16(30, 0, true);           // extra length
      entry.setUint16(32, 0, true);           // comment length
      entry.setUint16(34, 0, true);           // disk number
      entry.setUint16(36, 0, true);           // internal attributes
      entry.setUint32(38, 0, true);           // external attributes
      entry.setUint32(42, offset, true);      // offset of local header
      central.push(new Uint8Array(entry.buffer), nameBytes);

      offset += 30 + nameBytes.length + data.length;
    });

    var centralSize = central.reduce(function(sum, part) { return sum + part.length; }, 0);
    var end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054B50, true);       // end of central directory signature
    end.setUint16(8, central.length / 2, true);
    end.setUint16(10, central.length / 2, true);
    end.setUint32(12, centralSize, true);
    end.setUint32(16, offset, true);

    var all = parts.concat(central, [new Uint8Array(end.buffer)]);
    var result = new Uint8Array(all.reduce(function(sum, part) { return sum + part.length; }, 0));
    var position = 0;
    all.forEach(function(part) {
      result.set(part, position);
      position += part.length;
    });
    return result;
  }

  // Sheet names: at most 31 characters, none of : \ / ? * [ ]
  function sheetName(name) {
    return String(name).replace(/[:\\\/?*\[\]]/g, ' ').slice(0, 31) || 'Sheet';
  }

  return {
    build: function(sheets) {
      sheets = sheets.map(function(sheet) {
        return { name: sheetName(sheet.name), columns: sheet.columns, rows: sheet.rows };
      });
      return zip(workbookFiles(sheets));
    },
    MIME_TYPE: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
  };
})();

if (typeof module !== 'undefined') {
  module.exports = Xlsx;
}
