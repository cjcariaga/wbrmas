Option Explicit

Dim shell, root, exePath
Set shell = CreateObject("WScript.Shell")
root = Replace(WScript.ScriptFullName, "\start_bridge_hidden.vbs", "")
exePath = root & "\bin\x64\Release\FingerprintBridge.exe"
shell.CurrentDirectory = root
shell.Run Chr(34) & exePath & Chr(34), 0, False
