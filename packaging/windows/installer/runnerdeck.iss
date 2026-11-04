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
SetupIconFile=..\..\desktop\cmd\runnerdeck\icon.ico
UninstallDisplayIcon={app}\RunnerDeck.ico
OutputDir={#OutputDir}
OutputBaseFilename=RunnerDeck-{#AppVersion}-windows-x64-setup
Compression=lzma2/ultra
SolidCompression=yes
WizardStyle=modern
AppMutex=RunnerDeckTray
CloseApplications=no
VersionInfoVersion={#AppVersion}

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "vcredist"; Description: "Install the Microsoft Visual C++ runtime (PHP needs it)"; GroupDescription: "Required tools:"; Check: NeedVCRedist
Name: "webview2"; Description: "Install the Microsoft Edge WebView2 runtime (draws the RunnerDeck window)"; GroupDescription: "Required tools:"; Check: NeedWebView2
Name: "ghcli"; Description: "Install the GitHub CLI (RunnerDeck uses it to talk to GitHub)"; GroupDescription: "Required tools:"; Check: NeedGhCli
Name: "desktopicon"; Description: "Create a &desktop shortcut"; Flags: unchecked
Name: "startup"; Description: "Start RunnerDeck when I sign in to Windows"; Flags: unchecked

[Files]
Source: "{#SourceExe}"; DestDir: "{app}"; DestName: "RunnerDeck.exe"; Flags: ignoreversion
Source: "..\..\desktop\cmd\runnerdeck\icon.ico"; DestDir: "{app}"; DestName: "RunnerDeck.ico"; Flags: ignoreversion

[InstallDelete]
Type: files; Name: "{autoprograms}\RunnerDeck.lnk"

[Icons]
Name: "{group}\RunnerDeck"; Filename: "{app}\RunnerDeck.exe"; IconFilename: "{app}\RunnerDeck.ico"
Name: "{group}\Uninstall RunnerDeck"; Filename: "{uninstallexe}"
Name: "{autodesktop}\RunnerDeck"; Filename: "{app}\RunnerDeck.exe"; IconFilename: "{app}\RunnerDeck.ico"; Tasks: desktopicon

[Registry]
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "RunnerDeck"; ValueData: """{app}\RunnerDeck.exe"" --background"; Flags: uninsdeletevalue; Tasks: startup

[Run]
Filename: "{code:GhPath}"; Parameters: "auth login --hostname github.com --web --git-protocol https"; Description: "Sign in to GitHub (opens your browser)"; Flags: postinstall skipifsilent; Check: GhNeedsLogin
Filename: "{app}\RunnerDeck.exe"; Description: "Launch RunnerDeck"; Flags: nowait postinstall skipifsilent

[UninstallRun]
Filename: "{sys}\taskkill.exe"; Parameters: "/F /T /IM RunnerDeck.exe"; Flags: runhidden; RunOnceId: "StopRunnerDeck"

[UninstallDelete]
Type: filesandordirs; Name: "{localappdata}\RunnerDeck\runtime"

[Code]
function VCRuntimeInstalled(): Boolean;
var
  Installed: Cardinal;
begin
  Result := RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Installed', Installed)
    and (Installed = 1);
end;

function NeedVCRedist(): Boolean;
begin
  Result := not VCRuntimeInstalled();
end;

function GhPath(Param: String): String;
var
  Candidate: String;
begin
  Result := FileSearch('gh.exe', GetEnv('PATH'));
  if Result <> '' then
    Exit;
  Candidate := ExpandConstant('{commonpf64}\GitHub CLI\gh.exe');
  if FileExists(Candidate) then
  begin
    Result := Candidate;
    Exit;
  end;
  Candidate := ExpandConstant('{localappdata}\Programs\GitHub CLI\gh.exe');
  if FileExists(Candidate) then
    Result := Candidate;
end;

function WingetAvailable(): Boolean;
begin
  Result := (FileSearch('winget.exe', GetEnv('PATH')) <> '')
    or FileExists(ExpandConstant('{localappdata}\Microsoft\WindowsApps\winget.exe'));
end;

function NeedGhCli(): Boolean;
begin
  Result := (GhPath('') = '') and WingetAvailable();
end;

function GhNeedsLogin(): Boolean;
var
  Gh: String;
  Code: Integer;
begin
  Gh := GhPath('');
  Result := (Gh <> '')
    and Exec(Gh, 'auth status', '', SW_HIDE, ewWaitUntilTerminated, Code)
    and (Code <> 0);
end;

procedure ShowBusy(Text: String);
begin
  WizardForm.StatusLabel.Caption := Text;
  WizardForm.ProgressGauge.Style := npbstMarquee;
end;

function WebView2Installed(): Boolean;
var
  Version: String;
begin
  Result := False;
  if RegQueryStringValue(HKLM64, 'SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}', 'pv', Version)
     or RegQueryStringValue(HKLM64, 'SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}', 'pv', Version)
     or RegQueryStringValue(HKCU, 'Software\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}', 'pv', Version) then
    Result := (Version <> '') and (Version <> '0.0.0.0');
end;

function NeedWebView2(): Boolean;
begin
  Result := not WebView2Installed();
end;

procedure InstallMicrosoftPackage(Name, Url, FileName, Params: String);
var
  Dest, Verify: String;
  Code: Integer;
begin
  ShowBusy('Downloading ' + Name + '...');
  try
    DownloadTemporaryFile(Url, FileName, '', nil);
    Dest := ExpandConstant('{tmp}\' + FileName);
    Verify := '-NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "$s = Get-AuthenticodeSignature -LiteralPath ''' + Dest
      + '''; if ($s.Status -ne ''Valid'' -or $s.SignerCertificate.Subject -notmatch ''O=Microsoft Corporation'') { exit 1 }"';
    if not (Exec('powershell.exe', Verify, '', SW_HIDE, ewWaitUntilTerminated, Code) and (Code = 0)) then
      RaiseException('the download is not signed by Microsoft Corporation');
    ShowBusy('Installing ' + Name + '. Windows will ask for permission...');
    if not ShellExec('runas', Dest, Params, '', SW_SHOW, ewWaitUntilTerminated, Code) then
      RaiseException('the installer was cancelled');
    if (Code <> 0) and (Code <> 1638) and (Code <> 3010) then
      RaiseException('the installer exited with code ' + IntToStr(Code));
  except
    SuppressibleMsgBox(Name + ' could not be installed (' + GetExceptionMessage + ').' + #13#10
      + 'RunnerDeck may not start until it is installed. You can install it later from Microsoft.', mbInformation, MB_OK, IDOK);
  end;
  WizardForm.ProgressGauge.Style := npbstNormal;
end;

procedure InstallGhCli();
var
  Code: Integer;
begin
  ShowBusy('Installing the GitHub CLI. This can take a minute...');
  if not (Exec('winget.exe', 'install --id GitHub.cli -e --silent --accept-package-agreements --accept-source-agreements',
              '', SW_HIDE, ewWaitUntilTerminated, Code) and (Code = 0)) then
    SuppressibleMsgBox('The GitHub CLI could not be installed.' + #13#10
      + 'RunnerDeck will offer to install it again the first time it starts.', mbInformation, MB_OK, IDOK);
  WizardForm.ProgressGauge.Style := npbstNormal;
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep <> ssPostInstall then
    Exit;
  if WizardIsTaskSelected('vcredist') then
    InstallMicrosoftPackage('the Microsoft Visual C++ runtime', 'https://aka.ms/vs/17/release/vc_redist.x64.exe',
      'vc_redist.x64.exe', '/install /quiet /norestart');
  if WizardIsTaskSelected('webview2') then
    InstallMicrosoftPackage('the Microsoft Edge WebView2 runtime', 'https://go.microsoft.com/fwlink/p/?LinkId=2124703',
      'MicrosoftEdgeWebview2Setup.exe', '/silent /install');
  if WizardIsTaskSelected('ghcli') then
    InstallGhCli();
end;

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
