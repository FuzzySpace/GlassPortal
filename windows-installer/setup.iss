; ============================================================
;  Glasshouse NOC Provisioning Portal — Inno Setup Script
;  Bundles: PHP 8.3 + MariaDB 10.11 + Apache 2.4 + Portal app
;
;  Requirements to BUILD this installer:
;    1. Inno Setup 6+ installed  (https://jrsoftware.org/isinfo.php)
;    2. Download & extract into windows-installer\vendor\ before building:
;       - PHP 8.3 TS x64 zip  from https://windows.php.net/download/
;       - MariaDB 10.11 x64 ZIP (no-installer) from https://mariadb.org/download/
;       - Apache 2.4 x64 Win64 zip from https://www.apachelounge.com/download/
;    3. Run build.bat (or open this file in Inno Setup IDE and press F9)
; ============================================================

#define MyAppName      "Glasshouse NOC Portal"
#define MyAppVersion   "1.0.0"
#define MyAppPublisher "Glasshouse Hosting"
#define MyAppURL       "https://glasshouse.hosting"
#define MyAppExeName   "open-portal.bat"
#define ServicePort    "8080"
#define InstallDir     "C:\GlasshousePortal"

[Setup]
AppId={{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
DefaultDirName={#InstallDir}
DefaultGroupName={#MyAppName}
AllowNoIcons=no
LicenseFile=LICENSE.txt
OutputDir=dist
OutputBaseFilename=GlasshousePortal-Setup-{#MyAppVersion}
SetupIconFile=assets\glasshouse-icon.ico
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64
MinVersion=10.0
UninstallDisplayIcon={app}\launcher\open-portal.bat
CloseApplications=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon";    Description: "Create a desktop shortcut";         GroupDescription: "Additional icons:"; Flags: unchecked
Name: "startmenuicon";  Description: "Create Start Menu shortcuts";       GroupDescription: "Additional icons:";
Name: "autostart";      Description: "Start portal automatically at Windows startup"; GroupDescription: "Startup:"; Flags: unchecked

[Dirs]
Name: "{app}"
Name: "{app}\php"
Name: "{app}\mariadb"
Name: "{app}\apache"
Name: "{app}\htdocs"
Name: "{app}\data"
Name: "{app}\logs"
Name: "{app}\launcher"
Name: "{app}\service"
Name: "{app}\config"

; ---- PHP 8.3 (extract vendor\php\ contents here) ----
[Files]
Source: "vendor\php\*"; DestDir: "{app}\php"; Flags: ignoreversion recursesubdirs createallsubdirs
; ---- MariaDB 10.11 (extract vendor\mariadb\ contents here) ----
Source: "vendor\mariadb\*"; DestDir: "{app}\mariadb"; Flags: ignoreversion recursesubdirs createallsubdirs
; ---- Apache 2.4 ----
Source: "vendor\apache\*"; DestDir: "{app}\apache"; Flags: ignoreversion recursesubdirs createallsubdirs
; ---- Portal app ----
Source: "..\provisioning portal\*"; DestDir: "{app}\htdocs"; Flags: ignoreversion recursesubdirs createallsubdirs
; ---- Config templates ----
Source: "config\php.ini.template";   DestDir: "{app}\config"; Flags: ignoreversion
Source: "config\my.ini.template";    DestDir: "{app}\config"; Flags: ignoreversion
Source: "config\httpd.conf.template";DestDir: "{app}\config"; Flags: ignoreversion
; ---- Launcher scripts ----
Source: "launcher\start-portal.bat";  DestDir: "{app}\launcher"; Flags: ignoreversion
Source: "launcher\stop-portal.bat";   DestDir: "{app}\launcher"; Flags: ignoreversion
Source: "launcher\open-portal.bat";   DestDir: "{app}\launcher"; Flags: ignoreversion
; ---- Service management scripts ----
Source: "service\install-services.bat";  DestDir: "{app}\service"; Flags: ignoreversion
Source: "service\uninstall-services.bat";DestDir: "{app}\service"; Flags: ignoreversion

[Icons]
Name: "{group}\Open Glasshouse Portal";    Filename: "{app}\launcher\open-portal.bat";  WorkingDir: "{app}"
Name: "{group}\Start Portal Services";     Filename: "{app}\launcher\start-portal.bat"; WorkingDir: "{app}"
Name: "{group}\Stop Portal Services";      Filename: "{app}\launcher\stop-portal.bat";  WorkingDir: "{app}"
Name: "{group}\Uninstall {#MyAppName}";    Filename: "{uninstallexe}"
Name: "{autodesktop}\Open Glasshouse Portal"; Filename: "{app}\launcher\open-portal.bat"; Tasks: desktopicon

[Run]
; 1. Initialise MariaDB data directory
Filename: "{app}\mariadb\bin\mysql_install_db.exe"; Parameters: "--datadir=""{app}\data"" --service=GlasshousePortalDB --password=glasshouse"; WorkingDir: "{app}\mariadb\bin"; Flags: runhidden waituntilterminated; StatusMsg: "Initialising database..."

; 2. Start MariaDB temporarily for schema import
Filename: "{app}\mariadb\bin\mysqld.exe"; Parameters: "--defaults-file=""{app}\config\my.ini"" --standalone"; WorkingDir: "{app}\mariadb\bin"; Flags: runhidden nowait; StatusMsg: "Starting database..."
Filename: "{app}\launcher\wait-for-db.bat"; Flags: runhidden waituntilterminated; StatusMsg: "Waiting for database..."

; 3. Run PHP initialisation script (creates DB, imports schema, seeds admin)
Filename: "{app}\php\php.exe"; Parameters: """{app}\htdocs\init-db.php"""; WorkingDir: "{app}\htdocs"; Flags: runhidden waituntilterminated; StatusMsg: "Creating database and importing schema..."

; 4. Install Windows services
Filename: "{app}\service\install-services.bat"; Flags: runhidden waituntilterminated; StatusMsg: "Installing Windows services..."

; 5. Open portal in browser on finish
Filename: "http://localhost:{#ServicePort}/dashboard.php"; Flags: shellexec nowait postinstall; Description: "Open the portal in my browser now"

[UninstallRun]
Filename: "{app}\service\uninstall-services.bat"; Flags: runhidden waituntilterminated; RunOnceId: "StopServices"

[Code]
// ---- Wizard pages for first-run admin setup ----
var
  AdminEmailPage: TInputQueryWizardPage;
  AdminPassPage:  TInputQueryWizardPage;
  PortPage:       TInputQueryWizardPage;

procedure InitializeWizard;
begin
  AdminEmailPage := CreateInputQueryPage(
    wpSelectDir,
    'Admin Account Setup',
    'Create the first administrator account for the portal.',
    '');
  AdminEmailPage.Add('Admin email address:', False);
  AdminEmailPage.Values[0] := 'admin@glasshouse.local';

  AdminPassPage := CreateInputQueryPage(
    AdminEmailPage.ID,
    'Admin Password',
    'Set a strong password (minimum 10 characters).',
    '');
  AdminPassPage.Add('Password:', True);
  AdminPassPage.Add('Confirm password:', True);

  PortPage := CreateInputQueryPage(
    AdminPassPage.ID,
    'Web Server Port',
    'The portal will be accessible at http://localhost:<port>',
    '');
  PortPage.Add('Port number (default 8080):', False);
  PortPage.Values[0] := '8080';
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Email, Pass, Confirm, Port: String;
begin
  Result := True;

  if CurPageID = AdminEmailPage.ID then begin
    Email := Trim(AdminEmailPage.Values[0]);
    if Length(Email) = 0 then begin
      MsgBox('Please enter an admin email address.', mbError, MB_OK);
      Result := False;
    end;
  end;

  if CurPageID = AdminPassPage.ID then begin
    Pass    := AdminPassPage.Values[0];
    Confirm := AdminPassPage.Values[1];
    if Length(Pass) < 10 then begin
      MsgBox('Password must be at least 10 characters.', mbError, MB_OK);
      Result := False;
    end else if Pass <> Confirm then begin
      MsgBox('Passwords do not match. Please try again.', mbError, MB_OK);
      AdminPassPage.Values[0] := '';
      AdminPassPage.Values[1] := '';
      Result := False;
    end;
  end;

  if CurPageID = PortPage.ID then begin
    Port := Trim(PortPage.Values[0]);
    if (StrToIntDef(Port, 0) < 1024) or (StrToIntDef(Port, 0) > 65535) then begin
      MsgBox('Please enter a valid port number between 1024 and 65535.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

// Write admin credentials to a temp file read by init-db.php
procedure CurStepChanged(CurStep: TSetupStep);
var
  CredFile: String;
  Lines:    TArrayOfString;
begin
  if CurStep = ssInstall then begin
    CredFile := ExpandConstant('{tmp}\portal-init.ini');
    SetArrayLength(Lines, 3);
    Lines[0] := 'admin_email=' + AdminEmailPage.Values[0];
    Lines[1] := 'admin_password=' + AdminPassPage.Values[0];
    Lines[2] := 'port=' + PortPage.Values[0];
    SaveStringsToFile(CredFile, Lines, False);
  end;
end;
