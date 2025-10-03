# LearnDash Matrícula Temporária

Sistema completo de matrícula temporária com desmatrícula automática para WordPress + LearnDash.

## 📋 Funcionalidades

- ✅ Matrícula temporária de usuários em cursos LearnDash
- ✅ Desmatrícula automática após período definido (pseudo-cron)
- ✅ Tabela customizada no banco de dados para rastreamento
- ✅ Shortcode para exibir tabela de matrículas
- ✅ Interface Bootstrap responsiva
- ✅ Cálculo de tempo restante em tempo real
- ✅ Funções auxiliares para integração

## 🚀 Instalação

### Opção 1: Como Plugin
1. Copie o arquivo `ld-temp-enrollment.php` para `/wp-content/plugins/`
2. Ative o plugin no painel do WordPress
3. A tabela será criada automaticamente no banco de dados

### Opção 2: Como Snippet (Code Snippets)
1. Instale o plugin "Code Snippets"
2. Crie um novo snippet
3. Cole todo o conteúdo de `ld-temp-enrollment.php`
4. Ative o snippet

## 📖 Como Usar

### 1️⃣ Formulário de Matrícula (Novo!)

Adicione o formulário em qualquer página:

```
[ld_temp_enrollment_form]
```

Este shortcode renderiza um **formulário completo** com:
- ✅ Seleção de usuário (dropdown com todos os usuários)
- ✅ Seleção de curso (dropdown com todos os cursos LearnDash)
- ✅ Campo de duração em horas
- ✅ Botões de atalho (24h, 48h, 7 dias, 30 dias)
- ✅ Validação e feedback em tempo real
- ✅ Matrícula via AJAX (sem recarregar página)

**Permissões:** Apenas administradores podem ver e usar o formulário.

### 2️⃣ Tabela de Matrículas

Adicione a tabela em qualquer página ou post:

```
[ld_temp_enrollments_table]
```

**Parâmetros opcionais:**
- `status` - Filtrar por status (padrão: "active", opções: "active", "expired")
- `limit` - Limitar número de resultados (padrão: 100)
- `show_actions` - Mostrar botões de ação (padrão: "true")

**Exemplos:**
```
[ld_temp_enrollments_table status="active"]
[ld_temp_enrollments_table status="expired" limit="50"]
[ld_temp_enrollments_table show_actions="false"]
```

**Recursos da Tabela:**
- ✅ Botão "Desmatricular" em cada linha (apenas para matrículas ativas)
- ✅ Confirmação antes de desmatricular
- ✅ Remoção via AJAX (linha desaparece automaticamente)
- ✅ Apenas administradores veem os botões de ação

### 3️⃣ Página Completa Recomendada

Crie uma página com formulário + tabela:

```
<h2>Gerenciar Matrículas Temporárias</h2>

[ld_temp_enrollment_form]

<hr>

<h3>Matrículas Ativas</h3>
[ld_temp_enrollments_table status="active"]

<hr>

<h3>Matrículas Expiradas</h3>
[ld_temp_enrollments_table status="expired" show_actions="false"]
```

### Funções PHP

#### Matricular Usuário Temporariamente

```php
// Matricular por 24 horas (padrão)
ld_enroll_user_temporarily($user_id, $course_id);

// Matricular por 48 horas
ld_enroll_user_temporarily($user_id, $course_id, 48);

// Matricular por 7 dias (168 horas)
ld_enroll_user_temporarily($user_id, $course_id, 168);
```

#### Desmatricular Manualmente

```php
// Desmatricular usando ID do registro
ld_unenroll_user_temporarily($enrollment_id);
```

#### Obter Matrículas de um Usuário

```php
$enrollments = ld_get_user_temp_enrollments($user_id);
foreach ($enrollments as $enrollment) {
    echo $enrollment->course_id;
    echo $enrollment->expiration_date;
}
```

## 🗄️ Estrutura do Banco de Dados

Tabela: `wp_ld_temp_enrollments`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | bigint(20) | ID único do registro |
| user_id | bigint(20) | ID do usuário WordPress |
| course_id | bigint(20) | ID do curso LearnDash |
| expiration_date | datetime | Data/hora de expiração |
| enrolled_date | datetime | Data/hora de matrícula |
| status | varchar(20) | Status (active/expired) |

## ⚙️ Funcionamento do Pseudo-Cron

O sistema verifica matrículas expiradas automaticamente:
- Executa no hook `wp_loaded`
- Probabilidade de 10% a cada carregamento de página
- Processa todas as matrículas expiradas encontradas
- Remove acesso ao curso e atualiza status

**Nota:** Para sites com baixo tráfego, considere usar WP-Cron real ou cron do servidor.

## 🎨 Personalização da Tabela

A tabela usa Bootstrap 5.3.7 e é totalmente responsiva. Você pode personalizar os estilos editando a seção `<style>` no método `render_enrollments_table()`.

### Colunas Exibidas:
1. **Email** - Email do usuário
2. **Nome Completo** - Display name do usuário
3. **Curso** - Título do curso
4. **Data de Matrícula** - Quando foi matriculado
5. **Data de Expiração** - Quando expira
6. **Tempo Restante** - Calculado dinamicamente
7. **Status** - Badge colorido (Active/Expired)

## 🔧 Exemplo de Integração

### Formulário de Matrícula Temporária

```php
// Processar formulário
if (isset($_POST['enroll_temp'])) {
    $user_id = intval($_POST['user_id']);
    $course_id = intval($_POST['course_id']);
    $hours = intval($_POST['duration_hours']);
    
    $result = ld_enroll_user_temporarily($user_id, $course_id, $hours);
    
    if ($result) {
        echo "Usuário matriculado com sucesso!";
    }
}
```

### Hook Personalizado

```php
// Executar ação quando usuário for desmatriculado
add_action('wp_loaded', function() {
    global $ld_temp_enrollment;
    // Adicione lógica customizada aqui
});
```

## 📊 Boas Práticas

1. **Performance**: Para sites grandes, considere implementar WP-Cron real
2. **Backup**: Sempre faça backup antes de instalar
3. **Testes**: Teste em ambiente de desenvolvimento primeiro
4. **Logs**: Considere adicionar logs para auditoria
5. **Notificações**: Adicione emails de notificação antes da expiração

## 🛠️ Requisitos

- WordPress 5.0+
- LearnDash 3.0+
- PHP 7.4+
- MySQL 5.6+

## 📝 Licença

Código customizado para uso interno.

## 🐛 Troubleshooting

### Tabela não foi criada
- Verifique permissões do banco de dados
- Ative/desative o plugin novamente

### Desmatrícula não funciona
- Verifique se o pseudo-cron está executando
- Aumente a probabilidade temporariamente para testes

### Shortcode não exibe nada
- Verifique se há matrículas ativas
- Teste com `status="expired"` para ver registros antigos
