{{/* Labels put on every object, so `kubectl get all -l app.kubernetes.io/instance=<release>` finds them all */}}
{{- define "system-erp.labels" -}}
app.kubernetes.io/name: system-erp
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Values.image.tag | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/* Labels a Deployment or Service uses to find its pods; they must never change after the first install */}}
{{- define "system-erp.selector" -}}
app.kubernetes.io/name: system-erp
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/component: {{ .component }}
{{- end }}

{{- define "system-erp.image" -}}
{{ .Values.image.repository }}:{{ .Values.image.tag }}
{{- end }}

{{- define "system-erp.postgresHost" -}}
{{ .Release.Name }}-postgres
{{- end }}

{{/*
Environment of every container running the Symfony app (web, worker, migrations).
Passwords come from the Secret; $(POSTGRES_PASSWORD) is expanded by Kubernetes from the variable defined just before.
*/}}
{{- define "system-erp.appEnv" -}}
- name: APP_ENV
  value: prod
- name: APP_SECRET
  valueFrom:
    secretKeyRef:
      name: {{ .Values.existingSecret }}
      key: APP_SECRET
- name: MAILER_DSN
  valueFrom:
    secretKeyRef:
      name: {{ .Values.existingSecret }}
      key: MAILER_DSN
- name: POSTGRES_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.existingSecret }}
      key: POSTGRES_PASSWORD
- name: DATABASE_URL
  value: "postgresql://{{ .Values.postgres.user }}:$(POSTGRES_PASSWORD)@{{ include "system-erp.postgresHost" . }}:5432/{{ .Values.postgres.database }}?serverVersion=16&charset=utf8"
- name: LOCK_DSN
  value: "postgresql+advisory://{{ .Values.postgres.user }}:$(POSTGRES_PASSWORD)@{{ include "system-erp.postgresHost" . }}:5432/{{ .Values.postgres.database }}"
- name: DEFAULT_URI
  value: "{{ .Values.scheme }}://{{ .Values.host }}"
- name: SYMFONY_TRUSTED_PROXIES
  value: {{ .Values.trustedProxies | quote }}
- name: SYMFONY_TRUSTED_HEADERS
  value: "x-forwarded-for,x-forwarded-host,x-forwarded-port,x-forwarded-proto"
{{- end }}

{{/* Security settings shared by the app containers: no root, no privilege escalation, no extra kernel capabilities */}}
{{- define "system-erp.containerSecurity" -}}
allowPrivilegeEscalation: false
runAsNonRoot: true
runAsUser: 1000
capabilities:
  drop: ["ALL"]
{{- end }}

{{/*
Init container that keeps an app pod waiting until the database schema matches the code,
so a new version never runs against tables the migration job has not created yet.
*/}}
{{- define "system-erp.waitForMigrations" -}}
- name: wait-for-migrations
  image: {{ include "system-erp.image" . }}
  imagePullPolicy: {{ .Values.image.pullPolicy }}
  command: ["sh", "-c", "until php bin/console doctrine:migrations:up-to-date --no-interaction > /dev/null 2>&1; do echo 'waiting for migrations'; sleep 3; done"]
  env:
    {{- include "system-erp.appEnv" . | nindent 4 }}
  securityContext:
    {{- include "system-erp.containerSecurity" . | nindent 4 }}
{{- end }}
