# Changelog - LearnDash Matrícula Temporária

## Versão 1.6.1 (2025-10-03)

### 🎨 Ajuste de Layout

**Alteração:**
- Reordenado shortcodes na seção de informações
- Formulário de matrícula movido para o final da lista
- Melhoria na organização visual da documentação

---

## Versão 1.6.0 (2025-10-03)

### 🎨 Nova Página Administrativa

**Novo Recurso:**
- ✅ Adicionado menu "Matrícula Temporária" no sidebar do WordPress
- ✅ Menu aparece dentro do grupo LearnDash
- ✅ Página administrativa completa com:
  - Formulário de matrícula em lote
  - Tabela de matrículas ativas
  - Histórico de matrículas expiradas
  - Informações sobre shortcodes e WP-Cron
- ✅ Ícone dashicon-clock no título
- ✅ Design consistente com WordPress admin
- ✅ Shortcodes continuam funcionando em qualquer página

**Localização:**
```
WordPress Admin → LearnDash LMS → Matrícula Temporária
```

**Requisitos:**
- LearnDash deve estar ativo
- Permissão: `manage_options` (Administrador)

---

## Versão 1.5.1 (2025-10-03)

### 🐛 Correção Crítica: Bug no Índice Único

**Problema Identificado:**
- Índice único `(user_id, course_id, status)` impedia rematrícula após expiração
- Ao desmatricular (status='expired') e matricular novamente (status='active'), criava 2 linhas
- Botão "Desmatricular" não funcionava na segunda matrícula

**Solução Implementada:**
- ✅ Removido `status` do índice único
- ✅ Mantido histórico completo de matrículas (active + expired)
- ✅ Validação programática impede duplicatas ativas
- ✅ Novo índice: `KEY user_course (user_id, course_id)` para performance
- ✅ Retorno detalhado em duplicatas com ID e data de expiração existente

**Estrutura Atualizada:**
```sql
-- Removido: UNIQUE KEY unique_active_enrollment (user_id, course_id, status)
-- Adicionado: KEY user_course (user_id, course_id)
```

**Migração:**
```sql
-- Remover índice antigo (se existir)
ALTER TABLE wp_ld_temp_enrollments DROP INDEX unique_active_enrollment;
-- Adicionar novo índice
ALTER TABLE wp_ld_temp_enrollments ADD KEY user_course (user_id, course_id);
```

---

## Versão 1.5.0 (2025-10-03)

### 🔒 Correções de Segurança e Validação

#### 1. ✅ Corrigido retorno inconsistente em duplicatas
**Problema:** Função retornava string `'duplicate'` que era interpretada como sucesso.
**Solução:** 
- Agora retorna `array('error' => 'duplicate', 'message' => '...')` 
- AJAX verifica `is_int($result) && $result > 0` para confirmar sucesso real
- Mensagens de erro específicas para cada tipo de falha

#### 2. ✅ Proteção contra race condition
**Problema:** Duas requisições simultâneas podiam criar matrículas duplicadas.
**Solução:**
- Adicionado índice único na tabela: `UNIQUE KEY unique_active_enrollment (user_id, course_id, status)`
- Banco de dados agora impede duplicatas automaticamente
- **IMPORTANTE:** Execute `DROP TABLE wp_ld_temp_enrollments` e reative o plugin para criar a tabela com o novo índice

#### 3. ✅ Validação server-side robusta
**Problema:** Parâmetros não eram validados no servidor.
**Solução:**
- `duration_days` validado: deve ser entre 1 e 365 dias
- `course_id` validado: verifica se é um curso LearnDash válido
- Validação no AJAX antes de processar
- Validação na função `enroll_user_temporarily()`

#### 4. ✅ Proteção contra XSS
**Problema:** Emails maliciosos podiam injetar HTML/JavaScript.
**Solução:**
- Todos os emails são escapados com `esc_html()` antes de exibir
- Mensagens de erro escapadas
- Proteção em todas as saídas para o usuário

#### 5. ✅ Verificação de dependência LearnDash
**Problema:** Se LearnDash fosse desativado, causava fatal error.
**Solução:**
- Adicionado `function_exists('ld_update_course_access')` antes de usar
- Retorna erro amigável se LearnDash não estiver ativo
- Previne quebra do site

---

## Versão 1.4.0 (2025-10-03)

### 🔧 Melhorias de Performance

- **Removido pseudo-cron automático** que travava o site
- **Criado hook WP-Cron dedicado:** `ld_temp_check_expirations`
- Adicionado log de debug para monitoramento
- Contador de matrículas processadas

---

## Versão 1.3.1 (2025-10-03)

### 🕐 Correção de Timezone

- Corrigido problema de hora de matrícula com 3 horas de diferença
- Agora usa `current_time('mysql')` corretamente para timezone de Brasília (GMT-3)
- Data de matrícula e expiração agora sincronizadas

---

## Versão 1.3.0 (2025-10-03)

### 📧 Matrícula em Lote

- Substituído dropdown de usuários por textarea de emails
- Suporte para matricular múltiplos usuários de uma vez
- Um email por linha
- Relatório detalhado de sucessos e erros
- Validação individual de cada email

---

## Versão 1.2.0 (2025-10-03)

### 📅 Mudança de Horas para Dias

- Duração agora é em **dias** ao invés de horas
- Atalhos atualizados: 1 dia, 7 dias, 15 dias, 30 dias
- Máximo: 365 dias (1 ano)
- Timezone configurado para Brasília (GMT-3)

---

## Versão 1.1.0 (2025-10-03)

### 🎨 Interface e Funcionalidades

- Adicionado formulário de matrícula com shortcode `[ld_temp_enrollment_form]`
- Botão "Desmatricular" em cada linha da tabela
- Confirmação antes de desmatricular
- Remoção via AJAX sem recarregar página
- Interface Bootstrap 5.3.7 responsiva

---

## Versão 1.0.0 (2025-10-03)

### 🚀 Lançamento Inicial

- Sistema de matrícula temporária para LearnDash
- Tabela customizada no banco de dados
- Shortcode `[ld_temp_enrollments_table]`
- Desmatrícula automática via pseudo-cron
- Cálculo de tempo restante
- Status visual (active/expired)

---

## 🔄 Migração para Versão 1.5.0

### Passos Necessários:

1. **Atualizar o arquivo do plugin**
2. **Recriar a tabela com índice único:**
   ```sql
   DROP TABLE IF EXISTS wp_ld_temp_enrollments;
   ```
3. **Reativar o plugin** (a tabela será recriada automaticamente)
4. **Configurar WP-Cron** no WP Crontrol:
   - Hook: `ld_temp_check_expirations`
   - Recurrence: Hourly (recomendado)

### Verificar Funcionamento:

- Teste matricular o mesmo usuário duas vezes no mesmo curso
- Deve retornar erro: "Usuário já possui matrícula ativa neste curso"
- Verifique o log em `wp-content/debug.log` para confirmar execução do cron

---

## 📊 Estrutura Atual da Tabela

```sql
CREATE TABLE wp_ld_temp_enrollments (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    user_id bigint(20) NOT NULL,
    course_id bigint(20) NOT NULL,
    expiration_date datetime NOT NULL,
    enrolled_date datetime DEFAULT CURRENT_TIMESTAMP,
    status varchar(20) DEFAULT 'active',
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY course_id (course_id),
    KEY expiration_date (expiration_date),
    KEY status (status),
    UNIQUE KEY unique_active_enrollment (user_id, course_id, status)
);
```

**Novo:** Índice único `unique_active_enrollment` previne duplicatas.
