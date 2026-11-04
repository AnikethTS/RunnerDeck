#ifndef AppVersion
  #error Pass /DAppVersion=x.y.z
#endif
#ifndef SourceExe
  #error Pass /DSourceExe=path\to\RunnerDeck.exe
#endif
#ifndef OutputDir
  #define OutputDir "."
#endif
#ifndef RepoRoot
  #define RepoRoot "..\..\.."
#endif

[Setup]
AppId={{6F0B3E5A-2D41-4C57-9A0E-7B1D5C8E4F21}
AppName=RunnerDeck
AppVersion={#AppVersion}
AppPublisher=Aniketh TS and contributors
AppPublisherURL=https://github.com/AnikethTS/RunnerDeck
AppSupportURL=https://github.com/AnikethTS/RunnerDeck/issues
DefaultDirName={autopf}\RunnerDeck
DefaultGroupName=RunnerDeck
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
DisableProgramGroupPage=yes
LicenseFile={#RepoRoot}\LICENSE
SetupIconFile=..\launcher\icon.ico
UninstallDisplayIcon={app}\RunnerDeck.ico
OutputDir={#OutputDir}
OutputBaseFilename=RunnerDeck-Setup-{#AppVersion}
Compression=lzma2/ultra
SolidCompression=yes
WizardStyle=modern
AppMutex=RunnerDeckTray
CloseApplications=no
VersionInfoVersion={#AppVersion}

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; Flags: unchecked
Name: "startup"; Description: "Start RunnerDeck when I sign in to Windows"; Flags: unchecked

[Files]
Source: "{#SourceExe}"; DestDir: "{app}"; DestName: "RunnerDeck.exe"; Flags: ignoreversion
Source: "..\launcher\icon.ico"; DestDir: "{app}"; DestName: "RunnerDeck.ico"; Flags: ignoreversion

[Icons]
Name: "{autoprograms}\RunnerDeck"; Filename: "{app}\RunnerDeck.exe"; IconFilename: "{app}\RunnerDeck.ico"
Name: "{autodesktop}\RunnerDeck"; Filename: "{app}\RunnerDeck.exe"; IconFilename: "{app}\RunnerDeck.ico"; Tasks: desktopicon

[Registry]
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "RunnerDeck"; ValueData: """{app}\RunnerDeck.exe"" --no-browser"; Flags: uninsdeletevalue; Tasks: startup

[Run]
Filename: "{app}\RunnerDeck.exe"; Description: "Launch RunnerDeck"; Flags: nowait postinstall skipifsilent

[UninstallRun]
Filename: "{sys}\taskkill.exe"; Parameters: "/F /T /IM RunnerDeck.exe"; Flags: runhidden; RunOnceId: "StopRunnerDeck"

[UninstallDelete]
Type: filesandordirs; Name: "{localappdata}\RunnerDeck\runtime"

[Code]
procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  DataDir: String;
begin
  if CurUninstallStep <> usPostUninstall then
    Exit;
  DataDir := ExpandConstant('{localappdata}\RunnerDeck');
  if not DirExists(DataDir) then
    Exit;
  if UninstallSilent then
    Exit;
  if MsgBox('Also delete your RunnerDeck settings, history and runners?' + #13#10 + #13#10 + DataDir,
            mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES then
    DelTree(DataDir, True, True, True);
end;
