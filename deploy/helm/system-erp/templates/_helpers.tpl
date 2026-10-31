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

{{- define "system-erp.redisHost" -}}
{{ .Release.Name }}-redis
{{- end }}

{{- define "system-erp.rabbitmqHost" -}}
{{ .Release.Name }}-rabbitmq
{{- end }}

{{- define "system-erp.elasticsearchHost" -}}
{{ .Release.Name }}-elasticsearch
{{- end }}

{{/*
Environment of every container running the Symfony app (web, worker, migrations).
Passwords come from the Secret; $(POSTGRES_PASSWORD) and the others are expanded by Kubernetes from the variables defined before them.
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
- name: REDIS_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.existingSecret }}
      key: REDIS_PASSWORD
- name: REDIS_URL
  value: "redis://$(REDIS_PASSWORD)@{{ include "system-erp.redisHost" . }}:6379"
- name: RABBITMQ_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.existingSecret }}
      key: RABBITMQ_PASSWORD
- name: RABBITMQ_DSN
  value: "amqp://{{ .Values.rabbitmq.user }}:$(RABBITMQ_PASSWORD)@{{ include "system-erp.rabbitmqHost" . }}:5672"
- name: ELASTICSEARCH_ENABLED
  value: {{ ternary "1" "0" .Values.elasticsearch.enabled | quote }}
- name: ELASTICSEARCH_URL
  value: "http://{{ include "system-erp.elasticsearchHost" . }}:9200"
- name: ELASTICSEARCH_USERNAME
  value: elastic
- name: ELASTICSEARCH_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ .Values.existingSecret }}
      key: ELASTICSEARCH_PASSWORD
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
{{/*
Init container that keeps an app pod waiting until Redis and RabbitMQ accept connections.
Without it a pod started together with them (a fresh install, a restarted node) fails its first
requests and is restarted a few times before they are up. Only the ports are checked, with the PHP
of the app image, so it needs no password and works with any version of the image.
*/}}
{{- define "system-erp.waitForServices" -}}
- name: wait-for-services
  image: {{ include "system-erp.image" . }}
  imagePullPolicy: {{ .Values.image.pullPolicy }}
  command:
    - php
    - -r
    - |
      foreach (['{{ include "system-erp.redisHost" . }}' => 6379, '{{ include "system-erp.rabbitmqHost" . }}' => 5672] as $host => $port) {
          while (!@fsockopen($host, $port, $code, $error, 2)) {
              echo "waiting for $host:$port\n";
              sleep(3);
          }
      }
  securityContext:
    {{- include "system-erp.containerSecurity" . | nindent 4 }}
{{- end }}

{{/*
Init container of the search index job: Elasticsearch takes up to a minute to start, and building
the index before it answers would only fail. The web pods do not wait for it: search falls back
to the database meanwhile.
*/}}
{{- define "system-erp.waitForElasticsearch" -}}
- name: wait-for-elasticsearch
  image: {{ include "system-erp.image" . }}
  imagePullPolicy: {{ .Values.image.pullPolicy }}
  command:
    - php
    - -r
    - |
      while (!@fsockopen('{{ include "system-erp.elasticsearchHost" . }}', 9200, $code, $error, 2)) {
          echo "waiting for {{ include "system-erp.elasticsearchHost" . }}:9200\n";
          sleep(3);
      }
  securityContext:
    {{- include "system-erp.containerSecurity" . | nindent 4 }}
{{- end }}

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
