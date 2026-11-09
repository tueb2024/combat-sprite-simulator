# CI (Windows): a CLI app's full-screen screen in a real console, driven by keys (WriteConsoleInput), as a user types
# them: the keys must arrive one by one, unechoed (skeleton-cli's WindowsTerminal), not after Enter.
#   pwsh tools/ci/cli-keys.ps1 <app.exe> <data folder>
# Starts the app in a console of its own, attaches to it, types "a", "Milk", Enter, then "q", and checks that
# <data folder>/tasks.json has the task and that the app ended by itself.
param([string]$Exe, [string]$Data)
$ErrorActionPreference = 'Stop'

Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class Keys {
    [StructLayout(LayoutKind.Explicit, CharSet = CharSet.Unicode)]
    public struct INPUT_RECORD {
        [FieldOffset(0)] public ushort EventType;
        [FieldOffset(4)] public int bKeyDown;
        [FieldOffset(8)] public ushort wRepeatCount;
        [FieldOffset(10)] public ushort wVirtualKeyCode;
        [FieldOffset(12)] public ushort wVirtualScanCode;
        [FieldOffset(14)] public char UnicodeChar;
        [FieldOffset(16)] public uint dwControlKeyState;
    }
    [DllImport("kernel32.dll", SetLastError = true)] public static extern bool FreeConsole();
    [DllImport("kernel32.dll", SetLastError = true)] public static extern bool AttachConsole(uint pid);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    public static extern IntPtr CreateFileW(string name, uint access, uint share, IntPtr sa, uint disposition, uint flags, IntPtr template);
    [DllImport("kernel32.dll", SetLastError = true)]
    public static extern bool WriteConsoleInputW(IntPtr h, INPUT_RECORD[] records, uint length, out uint written);
    [DllImport("kernel32.dll")] public static extern bool CloseHandle(IntPtr h);

    /** Types text into the console of process pid (each character: key down + key up). */
    public static string Type(uint pid, string text) {
        FreeConsole();
        if (!AttachConsole(pid)) return "AttachConsole: " + Marshal.GetLastWin32Error();
        IntPtr input = CreateFileW("CONIN$", 0xC0000000, 3, IntPtr.Zero, 3, 0, IntPtr.Zero);
        if (input == new IntPtr(-1)) { FreeConsole(); return "CONIN$: " + Marshal.GetLastWin32Error(); }
        var records = new INPUT_RECORD[text.Length * 2];
        for (int i = 0; i < text.Length; i++) {
            for (int up = 0; up < 2; up++) {
                var r = new INPUT_RECORD();
                r.EventType = 1; r.bKeyDown = up == 0 ? 1 : 0; r.wRepeatCount = 1;
                r.wVirtualKeyCode = text[i] == '\r' ? (ushort)0x0D : (ushort)0; r.UnicodeChar = text[i];
                records[i * 2 + up] = r;
            }
        }
        uint written;
        bool ok = WriteConsoleInputW(input, records, (uint)records.Length, out written);
        CloseHandle(input);
        FreeConsole();
        return ok ? "" : "WriteConsoleInput: " + Marshal.GetLastWin32Error();
    }
}
'@

New-Item -ItemType Directory -Force -Path $Data | Out-Null
$env:JUMPHP_VAR = $Data
$p = Start-Process -FilePath $Exe -ArgumentList 'ui' -PassThru      # a console of its own, the screen
Start-Sleep -Seconds 8                           # the first start unpacks the app
if ($p.HasExited) { throw "the app ended at once (exit code $($p.ExitCode))" }
foreach ($keys in @('a', 'Milk', "`r", 'q')) {
    $err = [Keys]::Type([uint32]$p.Id, $keys)
    if ($err -ne '') { throw $err }
    Start-Sleep -Milliseconds 800
}
if (-not $p.WaitForExit(15000)) { $p.Kill(); throw 'the app did not end with q: the keys did not reach its screen' }
$file = Join-Path $Data 'tasks.json'
if (-not (Test-Path $file)) { throw "no ${file}: the task was not added" }
$tasks = Get-Content $file -Raw
Write-Output $tasks
if ($tasks -notmatch 'Milk') { throw 'the task typed is not in tasks.json' }
Write-Output '==> the full-screen screen read the keys one by one on a Windows console'
