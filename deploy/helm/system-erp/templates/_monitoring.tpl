{{/*
What Prometheus collects: the same in the full local setup and in the agent that sends to Grafana Cloud.
*/}}
{{- define "system-erp.scrapeConfigs" -}}
scrape_configs:
  - job_name: prometheus
    static_configs:
      - targets: ["localhost:9090"]
  # Each web pod separately, so one misbehaving copy is visible
  - job_name: web
    kubernetes_sd_configs:
      - role: pod
        namespaces:
          names: [{{ .Release.Namespace | quote }}]
    relabel_configs:
      - source_labels: [__meta_kubernetes_pod_label_app_kubernetes_io_component, __meta_kubernetes_pod_container_port_name]
        regex: web;metrics
        action: keep
      - source_labels: [__meta_kubernetes_pod_name]
        target_label: pod
  - job_name: traefik
    kubernetes_sd_configs:
      - role: pod
        namespaces:
          names: [kube-system]
    relabel_configs:
      - source_labels: [__meta_kubernetes_pod_label_app_kubernetes_io_name, __meta_kubernetes_pod_container_port_name]
        regex: traefik;metrics
        action: keep
  - job_name: postgres
    static_configs:
      - targets: ["{{ .Release.Name }}-exporters:9187"]
  - job_name: redis
    static_configs:
      - targets: ["{{ .Release.Name }}-exporters:9121"]
  - job_name: rabbitmq
    # Per queue, not only totals: the plugin built into the image serves this without any setup
    metrics_path: /metrics/per-object
    static_configs:
      - targets: ["{{ .Release.Name }}-rabbitmq:15692"]
    metric_relabel_configs:
      - source_labels: [__name__]
        regex: rabbitmq_queue_messages(_ready|_unacked)?|rabbitmq_identity_info
        action: keep
  # CPU and memory of every container on the node. Only three of the many cAdvisor metrics are kept
  - job_name: containers
    scheme: https
    authorization:
      credentials_file: /var/run/secrets/kubernetes.io/serviceaccount/token
    tls_config:
      ca_file: /var/run/secrets/kubernetes.io/serviceaccount/ca.crt
    kubernetes_sd_configs:
      - role: node
    relabel_configs:
      - target_label: __address__
        replacement: kubernetes.default.svc:443
      - source_labels: [__meta_kubernetes_node_name]
        target_label: __metrics_path__
        regex: (.+)
        replacement: /api/v1/nodes/$1/proxy/metrics/cadvisor
    metric_relabel_configs:
      - source_labels: [__name__]
        regex: container_cpu_usage_seconds_total|container_memory_working_set_bytes|container_spec_memory_limit_bytes
        action: keep
      # Without the name: the cgroups of whole pods and system services, counted twice otherwise
      - source_labels: [container]
        regex: "|POD"
        action: drop
{{- end }}
