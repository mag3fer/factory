# Builds logo variants from the original artwork (light textured background):
#  logo-mark.png  : emblem only, background removed (flood fill from the edges) — sidebar / login / favicon
#  logo-full.png  : emblem + name, background flattened to white — invoices
#  logo-label.png : full logo, grayscale high contrast on white — thermal sticker
param([string]$Src, [string]$OutDir)
Add-Type -AssemblyName System.Drawing
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @"
using System; using System.Drawing; using System.Drawing.Imaging; using System.Collections.Generic;
public static class LogoTool {
  static bool IsBg(Color c) {
    int mx = Math.Max(c.R, Math.Max(c.G, c.B)), mn = Math.Min(c.R, Math.Min(c.G, c.B));
    return mx > 188 && (mx - mn) < 22;
  }
  public static Bitmap Crop(Bitmap src, Rectangle r) { return src.Clone(r, PixelFormat.Format32bppArgb); }
  // remove background reachable from the border; soften the edge
  public static void RemoveBg(Bitmap b) {
    int w = b.Width, h = b.Height; var seen = new bool[w, h]; var q = new Queue<Point>();
    for (int x = 0; x < w; x++) { q.Enqueue(new Point(x, 0)); q.Enqueue(new Point(x, h - 1)); }
    for (int y = 0; y < h; y++) { q.Enqueue(new Point(0, y)); q.Enqueue(new Point(w - 1, y)); }
    while (q.Count > 0) {
      var p = q.Dequeue(); if (p.X < 0 || p.Y < 0 || p.X >= w || p.Y >= h || seen[p.X, p.Y]) continue;
      seen[p.X, p.Y] = true; var c = b.GetPixel(p.X, p.Y); if (!IsBg(c)) continue;
      b.SetPixel(p.X, p.Y, Color.FromArgb(0, c));
      q.Enqueue(new Point(p.X + 1, p.Y)); q.Enqueue(new Point(p.X - 1, p.Y)); q.Enqueue(new Point(p.X, p.Y + 1)); q.Enqueue(new Point(p.X, p.Y - 1));
    }
    // anti-alias: pixels next to transparent ones that are light become semi-transparent
    for (int y = 1; y < h - 1; y++) for (int x = 1; x < w - 1; x++) {
      var c = b.GetPixel(x, y); if (c.A == 0) continue;
      bool edge = b.GetPixel(x + 1, y).A == 0 || b.GetPixel(x - 1, y).A == 0 || b.GetPixel(x, y + 1).A == 0 || b.GetPixel(x, y - 1).A == 0;
      if (edge) { int mx = Math.Max(c.R, Math.Max(c.G, c.B)); if (mx > 160) b.SetPixel(x, y, Color.FromArgb(Math.Max(40, 255 - (mx - 160) * 2), c)); }
    }
  }
  public static void Whiten(Bitmap b, bool gray) {
    for (int y = 0; y < b.Height; y++) for (int x = 0; x < b.Width; x++) {
      var c = b.GetPixel(x, y);
      if (IsBg(c)) { b.SetPixel(x, y, Color.White); continue; }
      if (gray) { int l = (int)(c.R * .3 + c.G * .59 + c.B * .11); l = l > 175 ? 255 : (int)(l * 0.75); b.SetPixel(x, y, Color.FromArgb(255, l, l, l)); }
    }
  }
  public static Bitmap Square(Bitmap src, int size) {
    var o = new Bitmap(size, size, PixelFormat.Format32bppArgb);
    using (var g = Graphics.FromImage(o)) {
      g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic; g.Clear(Color.Transparent);
      float s = Math.Min((float)size / src.Width, (float)size / src.Height);
      int w = (int)(src.Width * s), h = (int)(src.Height * s); g.DrawImage(src, (size - w) / 2, (size - h) / 2, w, h);
    }
    return o;
  }
}
"@
$img = New-Object System.Drawing.Bitmap $Src
"source: $($img.Width)x$($img.Height)"
# emblem bounding box measured on the 1574x656 artwork
$mark = [LogoTool]::Crop($img, (New-Object System.Drawing.Rectangle 535, 0, 510, 512))
[LogoTool]::RemoveBg($mark)
$sq = [LogoTool]::Square($mark, 256); $sq.Save("$OutDir\logo-mark.png", [System.Drawing.Imaging.ImageFormat]::Png)
$full = [LogoTool]::Crop($img, (New-Object System.Drawing.Rectangle 410, 0, 760, 656))
[LogoTool]::Whiten($full, $false); $full.Save("$OutDir\logo-full.png", [System.Drawing.Imaging.ImageFormat]::Png)
$lab = [LogoTool]::Crop($img, (New-Object System.Drawing.Rectangle 410, 0, 760, 656))
[LogoTool]::Whiten($lab, $true); $lab.Save("$OutDir\logo-label.png", [System.Drawing.Imaging.ImageFormat]::Png)
"done"
