<?php
/**
 * GoBiblia.com.br - Integração com ABíbliaDigital API
 * Classe completa para gerenciar toda a Bíblia via API
 */

class GoBibliaAPI {
    private $base_url = 'https://www.abibliadigital.com.br/api';
    private $token = null;
    private $default_version = 'nvi';
    
    public function __construct($token = null) {
        $this->token = $token;
    }
    
    /**
     * Fazer requisição para a API
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->base_url . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        // Adicionar token se disponível
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        if ($data && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Erro cURL: " . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Erro API: HTTP {$httpCode}");
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new Exception("Erro ao decodificar JSON");
        }
        
        return $decoded;
    }
    
    /**
     * Buscar todos os livros bíblicos
     */
    public function getBooks() {
        try {
            return $this->makeRequest('/books');
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Buscar capítulo completo
     * @param string $version - nvi, acf, ra, etc
     * @param string $book - gn, ex, mt, jo, etc
     * @param int $chapter - número do capítulo
     */
    public function getChapter($version, $book, $chapter) {
        try {
            return $this->makeRequest("/verses/{$version}/{$book}/{$chapter}");
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Buscar versículo específico
     */
    public function getVerse($version, $book, $chapter, $verse) {
        try {
            return $this->makeRequest("/verses/{$version}/{$book}/{$chapter}/{$verse}");
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Buscar versículo aleatório
     */
    public function getRandomVerse($version = null, $book = null) {
        $version = $version ?: $this->default_version;
        
        try {
            if ($book) {
                return $this->makeRequest("/verses/{$version}/{$book}/random");
            } else {
                return $this->makeRequest("/verses/{$version}/random");
            }
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Buscar versículos por palavra-chave
     */
    public function searchVerses($word, $version = null) {
        $version = $version ?: $this->default_version;
        
        try {
            return $this->makeRequest('/verses/search', 'POST', [
                'version' => $version,
                'search' => $word
            ]);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Buscar versões disponíveis
     */
    public function getVersions() {
        try {
            return $this->makeRequest('/versions');
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

/**
 * Classe para gerenciar leituras do usuário (salvar localmente)
 */
class GoBibliaManager {
    private $api;
    private $pdo;
    
    public function __construct($token = null) {
        $this->api = new GoBibliaAPI($token);
        $this->initDatabase();
    }
    
    /**
     * Inicializar banco local para salvar progresso
     */
    private function initDatabase() {
        try {
            // Configurar conexão (SQLite para simplicidade)
            $this->pdo = new PDO('sqlite:gobiblia.db');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Criar tabela se não existir
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS leituras (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    versao VARCHAR(10),
                    livro VARCHAR(50),
                    capitulo INTEGER,
                    versiculo INTEGER,
                    data_leitura DATE DEFAULT CURRENT_DATE,
                    anotacoes TEXT,
                    favorito BOOLEAN DEFAULT 0,
                    data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS estatisticas (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    total_capitulos INTEGER DEFAULT 0,
                    total_versiculos INTEGER DEFAULT 0,
                    dias_lendo INTEGER DEFAULT 0,
                    livros_completos INTEGER DEFAULT 0,
                    ultima_atualizacao DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
        } catch (PDOException $e) {
            error_log("Erro banco: " . $e->getMessage());
        }
    }
    
    /**
     * Registrar leitura de capítulo
     */
    public function registrarLeitura($versao, $livro, $capitulo, $anotacoes = '') {
        try {
            $stmt = $this->pdo->prepare("
                INSERT OR REPLACE INTO leituras 
                (versao, livro, capitulo, anotacoes) 
                VALUES (?, ?, ?, ?)
            ");
            
            return $stmt->execute([$versao, $livro, $capitulo, $anotacoes]);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Buscar histórico de leituras
     */
    public function getHistoricoLeituras($limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM leituras 
                ORDER BY data_leitura DESC, data_criacao DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Obter estatísticas
     */
    public function getEstatisticas() {
        try {
            // Calcular estatísticas
            $total_capitulos = $this->pdo->query("SELECT COUNT(DISTINCT livro||capitulo) FROM leituras")->fetchColumn();
            $dias_lendo = $this->pdo->query("SELECT COUNT(DISTINCT data_leitura) FROM leituras")->fetchColumn();
            
            return [
                'total_capitulos' => $total_capitulos ?: 0,
                'dias_lendo' => $dias_lendo ?: 0,
                'livros_iniciados' => $this->pdo->query("SELECT COUNT(DISTINCT livro) FROM leituras")->fetchColumn() ?: 0,
                'total_leituras' => $this->pdo->query("SELECT COUNT(*) FROM leituras")->fetchColumn() ?: 0
            ];
        } catch (PDOException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

// ====== EXEMPLO DE USO ======

// Instanciar o gerenciador
$gobiblia = new GoBibliaManager();

// ====== FUNÇÕES PARA USAR NO FRONT-END ======

/**
 * Função para buscar capítulo (para AJAX)
 */
if (isset($_GET['action']) && $_GET['action'] === 'get_chapter') {
    header('Content-Type: application/json');
    
    $version = $_GET['version'] ?? 'nvi';
    $book = $_GET['book'] ?? 'gn';
    $chapter = $_GET['chapter'] ?? 1;
    
    $resultado = $gobiblia->api->getChapter($version, $book, $chapter);
    echo json_encode($resultado);
    exit;
}

/**
 * Função para buscar livros
 */
if (isset($_GET['action']) && $_GET['action'] === 'get_books') {
    header('Content-Type: application/json');
    
    $resultado = $gobiblia->api->getBooks();
    echo json_encode($resultado);
    exit;
}

/**
 * Função para buscar versículo aleatório
 */
if (isset($_GET['action']) && $_GET['action'] === 'random_verse') {
    header('Content-Type: application/json');
    
    $version = $_GET['version'] ?? 'nvi';
    $resultado = $gobiblia->api->getRandomVerse($version);
    echo json_encode($resultado);
    exit;
}

/**
 * Função para buscar
 */
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    
    $word = $_GET['word'] ?? '';
    $version = $_GET['version'] ?? 'nvi';
    
    if ($word) {
        $resultado = $gobiblia->api->searchVerses($word, $version);
    } else {
        $resultado = ['error' => 'Palavra de busca obrigatória'];
    }
    
    echo json_encode($resultado);
    exit;
}

/**
 * Registrar leitura
 */
if (isset($_POST['action']) && $_POST['action'] === 'registrar_leitura') {
    header('Content-Type: application/json');
    
    $versao = $_POST['versao'] ?? 'nvi';
    $livro = $_POST['livro'] ?? '';
    $capitulo = $_POST['capitulo'] ?? '';
    $anotacoes = $_POST['anotacoes'] ?? '';
    
    if ($livro && $capitulo) {
        $sucesso = $gobiblia->registrarLeitura($versao, $livro, $capitulo, $anotacoes);
        echo json_encode(['success' => $sucesso]);
    } else {
        echo json_encode(['error' => 'Dados obrigatórios']);
    }
    exit;
}

/**
 * Buscar estatísticas
 */
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    header('Content-Type: application/json');
    
    $stats = $gobiblia->getEstatisticas();
    echo json_encode($stats);
    exit;
}

/**
 * Histórico
 */
if (isset($_GET['action']) && $_GET['action'] === 'historico') {
    header('Content-Type: application/json');
    
    $limit = $_GET['limit'] ?? 10;
    $historico = $gobiblia->getHistoricoLeituras($limit);
    echo json_encode($historico);
    exit;
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GoBiblia - Powered by ABíbliaDigital</title>
    <style>
        /* Mesmo CSS do protótipo anterior */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Georgia', serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; margin-bottom: 40px; color: white; }
        .header h1 { font-size: 2.5rem; margin-bottom: 10px; }
        .api-credit { font-size: 0.9rem; opacity: 0.8; margin-top: 10px; }
        .loading { text-align: center; color: white; font-size: 1.2rem; margin: 50px 0; }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <h1>📖 GoBiblia</h1>
            <p>Sua Bíblia Online Completa</p>
            <div class="api-credit">Powered by ABíbliaDigital.com.br</div>
        </header>
        
        <div class="loading" id="loading">
            🔄 Carregando Bíblia da API...
        </div>
        
        <div id="app-content" style="display: none;">
            <!-- Conteúdo será carregado via JavaScript -->
        </div>
    </div>

    <script>
        // Classe JavaScript para gerenciar a API
        class GoBibliaApp {
            constructor() {
                this.currentBook = 'gn';
                this.currentChapter = 1;
                this.currentVersion = 'nvi';
                this.init();
            }
            
            async init() {
                try {
                    // Carregar livros
                    await this.loadBooks();
                    
                    // Carregar capítulo inicial
                    await this.loadChapter();
                    
                    // Esconder loading
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('app-content').style.display = 'block';
                    
                } catch (error) {
                    console.error('Erro ao inicializar:', error);
                    document.getElementById('loading').innerHTML = '❌ Erro ao carregar. Tente recarregar a página.';
                }
            }
            
            async loadBooks() {
                const response = await fetch('?action=get_books');
                const books = await response.json();
                
                if (books.error) {
                    throw new Error(books.error);
                }
                
                this.books = books;
                console.log('Livros carregados:', books.length);
            }
            
            async loadChapter() {
                const response = await fetch(`?action=get_chapter&version=${this.currentVersion}&book=${this.currentBook}&chapter=${this.currentChapter}`);
                const chapter = await response.json();
                
                if (chapter.error) {
                    throw new Error(chapter.error);
                }
                
                this.displayChapter(chapter);
            }
            
            displayChapter(chapter) {
                const content = document.getElementById('app-content');
                
                let versesHtml = '';
                if (chapter.verses) {
                    versesHtml = chapter.verses.map(verse => 
                        `<div class="verse" onclick="selectVerse(${verse.number})">
                            <span class="verse-number">${verse.number}</span>
                            ${verse.text}
                        </div>`
                    ).join('');
                }
                
                content.innerHTML = `
                    <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.1);">
                        <h1 style="text-align: center; color: #667eea; margin-bottom: 30px;">
                            ${chapter.book?.name || 'Livro'} ${chapter.chapter?.number || this.currentChapter}
                        </h1>
                        
                        <div style="line-height: 2; font-size: 1.1rem;">
                            ${versesHtml}
                        </div>
                        
                        <div style="margin-top: 30px; text-align: center;">
                            <button onclick="app.previousChapter()" style="margin: 10px; padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">← Anterior</button>
                            <button onclick="app.nextChapter()" style="margin: 10px; padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">Próximo →</button>
                            <button onclick="app.randomVerse()" style="margin: 10px; padding: 10px 20px; background: #764ba2; color: white; border: none; border-radius: 5px; cursor: pointer;">🎲 Aleatório</button>
                        </div>
                        
                        <div style="margin-top: 20px; text-align: center; font-size: 0.9rem; color: #666;">
                            Versão: ${this.currentVersion.toUpperCase()} • Livro: ${this.currentBook.toUpperCase()} • Cap: ${this.currentChapter}
                        </div>
                    </div>
                `;
            }
            
            async previousChapter() {
                if (this.currentChapter > 1) {
                    this.currentChapter--;
                    await this.loadChapter();
                }
            }
            
            async nextChapter() {
                this.currentChapter++;
                await this.loadChapter();
            }
            
            async randomVerse() {
                const response = await fetch(`?action=random_verse&version=${this.currentVersion}`);
                const verse = await response.json();
                
                if (!verse.error) {
                    alert(`"${verse.text}"\n\n${verse.book?.name} ${verse.chapter}:${verse.number}`);
                }
            }
        }
        
        // Inicializar app
        const app = new GoBibliaApp();
        
        function selectVerse(number) {
            // Highlight verse
            document.querySelectorAll('.verse').forEach(v => v.style.background = '');
            event.target.closest('.verse').style.background = '#fef3c7';
        }
    </script>
</body>
</html>
