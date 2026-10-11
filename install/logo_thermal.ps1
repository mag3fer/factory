# Thermal-printer version of the logo: pure black/white (no grey dithering), whitespace trimmed.
# Usage: logo_thermal.ps1 -Src logo-full.png -Out logo-label.png [-Threshold 150]
param([string]$Src, [string]$Out, [int]$Threshold = 150, [double]$Contrast = 1.5)
Add-Type -AssemblyName System.Drawing
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @"
using System; using System.Drawing; using System.Drawing.Imaging;
public static class Thermal {
  public static Bitmap Make(Bitmap src, int threshold, double contrast) {
    int w = src.Width, h = src.Height;
    var bw = new bool[w, h];
    int minX = w, minY = h, maxX = -1, maxY = -1;
    for (int y = 0; y < h; y++) for (int x = 0; x < w; x++) {
      var c = src.GetPixel(x, y);
      double l = c.R * .3 + c.G * .59 + c.B * .11;
      l = (l - 128) * contrast + 128;              // push mid-greys apart before thresholding
      bool black = l < threshold;
      bw[x, y] = black;
      if (black) { if (x < minX) minX = x; if (y < minY) minY = y; if (x > maxX) maxX = x; if (y > maxY) maxY = y; }
    }
    int pad = 4; minX = Math.Max(0, minX - pad); minY = Math.Max(0, minY - pad); maxX = Math.Min(w - 1, maxX + pad); maxY = Math.Min(h - 1, maxY + pad);
    var o = new Bitmap(maxX - minX + 1, maxY - minY + 1, PixelFormat.Format32bppArgb);
    for (int y = minY; y <= maxY; y++) for (int x = minX; x <= maxX; x++)
      o.SetPixel(x - minX, y - minY, bw[x, y] ? Color.Black : Color.White);
    return o;
  }
}
"@
$img = New-Object System.Drawing.Bitmap $Src
$o = [Thermal]::Make($img, $Threshold, $Contrast)
$img.Dispose()
$o.Save($Out, [System.Drawing.Imaging.ImageFormat]::Png)
"$Out $($o.Width)x$($o.Height)"
