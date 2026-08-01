<?php

class NotionTasks extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('NotionToken', '');
        $this->RegisterPropertyString('DatabaseID', 'c53ffce3985d4bae96f0961aab197c9f');
        $this->RegisterPropertyInteger('MaxItems', 12);
        $this->RegisterPropertyInteger('RefreshMinutes', 15);
        $this->RegisterPropertyString('TaskView', 'today');
        $this->RegisterPropertyString('TitleProperty', 'Name / Aufgabe');
        $this->RegisterPropertyString('StatusProperty', 'Status');
        $this->RegisterPropertyString('DoneProperty', 'Erledigt');
        $this->RegisterPropertyString('DueProperty', 'Zieldatum');
        $this->RegisterPropertyString('PriorityProperty', 'Priorität');
        $this->RegisterPropertyBoolean('HideDone', true);
        $this->RegisterPropertyInteger('DaysAhead', 30);
        $this->RegisterTimer('RefreshTimer', 0, 'NTA_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);
        $minutes = $this->ReadPropertyInteger('RefreshMinutes');
        $this->SetTimerInterval('RefreshTimer', $minutes > 0 ? $minutes * 60 * 1000 : 0);
        $this->Refresh();
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $payload = json_encode($this->BuildPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return str_replace('__INITIAL_NOTION_TASKS_PAYLOAD__', $payload ?: '{}', $html);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Refresh') {
            $this->Refresh();
        }
    }

    public function Refresh()
    {
        $payload = $this->BuildPayload();
        $this->UpdateVisualizationValue(json_encode($payload));
    }

    private function BuildPayload(): array
    {
        $token = trim($this->ReadPropertyString('NotionToken'));
        $db = $this->NormalizeId($this->ReadPropertyString('DatabaseID'));
        if ($token === '') {
            return ['error' => 'Notion Integration Token fehlt.', 'tasks' => [], 'updatedAt' => date('d.m.Y H:i')];
        }
        if ($db === '') {
            return ['error' => 'Notion Database ID fehlt.', 'tasks' => [], 'updatedAt' => date('d.m.Y H:i')];
        }

        $body = $this->BuildQueryBody();
        $result = $this->NotionRequest('https://api.notion.com/v1/databases/' . $db . '/query', $token, $body);
        if (isset($result['error'])) {
            return ['error' => $result['error'], 'tasks' => [], 'updatedAt' => date('d.m.Y H:i')];
        }

        $tasks = [];
        foreach (($result['results'] ?? []) as $page) {
            $tasks[] = $this->ParseTask($page);
        }
        usort($tasks, function ($a, $b) {
            $pa = $this->PriorityRank($a['priority'] ?? '');
            $pb = $this->PriorityRank($b['priority'] ?? '');
            if ($pa !== $pb) return $pa <=> $pb;
            return strcmp($a['due'] ?? '9999-12-31', $b['due'] ?? '9999-12-31');
        });
        return ['updatedAt' => date('d.m.Y H:i'), 'title' => $this->ViewTitle(), 'tasks' => $tasks];
    }

    private function BuildQueryBody(): array
    {
        $filters = [];
        if ($this->ReadPropertyBoolean('HideDone')) {
            $filters[] = ['property' => $this->ReadPropertyString('DoneProperty'), 'checkbox' => ['equals' => false]];
            $filters[] = ['property' => $this->ReadPropertyString('StatusProperty'), 'status' => ['does_not_equal' => 'Erledigt']];
        }
        $dueProperty = $this->ReadPropertyString('DueProperty');
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $until30 = date('Y-m-d', strtotime('+30 days'));
        switch ($this->ReadPropertyString('TaskView')) {
            case 'tomorrow':
                $filters[] = ['property' => $dueProperty, 'date' => ['equals' => $tomorrow]];
                break;
            case 'next30':
                $filters[] = ['property' => $dueProperty, 'date' => ['on_or_after' => $today]];
                $filters[] = ['property' => $dueProperty, 'date' => ['on_or_before' => $until30]];
                break;
            case 'past':
                $filters[] = ['property' => $dueProperty, 'date' => ['before' => $today]];
                break;
            case 'today':
            default:
                $filters[] = ['property' => $dueProperty, 'date' => ['equals' => $today]];
                break;
        }
        $filter = count($filters) === 1 ? $filters[0] : ['and' => $filters];
        return [
            'page_size' => $this->ReadPropertyInteger('MaxItems'),
            'filter' => $filter,
            'sorts' => [
                ['property' => $this->ReadPropertyString('DueProperty'), 'direction' => 'ascending'],
                ['property' => $this->ReadPropertyString('PriorityProperty'), 'direction' => 'ascending']
            ]
        ];
    }

    private function ViewTitle(): string
    {
        switch ($this->ReadPropertyString('TaskView')) {
            case 'tomorrow': return 'Aufgaben morgen';
            case 'next30': return 'Aufgaben nächste 30 Tage';
            case 'past': return 'Überfällige Aufgaben';
            case 'today':
            default: return 'Aufgaben heute';
        }
    }

    private function NotionRequest(string $url, string $token, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Notion-Version: 2022-06-28'
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $err) {
            return ['error' => 'Notion API Fehler: ' . $err];
        }
        $json = json_decode($response, true);
        if ($code < 200 || $code >= 300) {
            $msg = $json['message'] ?? $response;
            return ['error' => 'Notion API HTTP ' . $code . ': ' . $msg];
        }
        return is_array($json) ? $json : ['error' => 'Ungültige Notion Antwort.'];
    }

    private function ParseTask(array $page): array
    {
        $p = $page['properties'] ?? [];
        $title = $this->PlainTitle($p[$this->ReadPropertyString('TitleProperty')] ?? []);
        $status = $p[$this->ReadPropertyString('StatusProperty')]['status']['name'] ?? '';
        $priority = $p[$this->ReadPropertyString('PriorityProperty')]['select']['name'] ?? '';
        $due = $p[$this->ReadPropertyString('DueProperty')]['date']['start'] ?? '';
        if (strlen($due) > 10) $due = substr($due, 0, 10);
        return [
            'title' => $title !== '' ? $title : 'Ohne Titel',
            'status' => $status,
            'priority' => $priority,
            'due' => $due,
            'url' => $page['url'] ?? ''
        ];
    }

    private function PlainTitle(array $prop): string
    {
        $parts = $prop['title'] ?? [];
        $out = '';
        foreach ($parts as $part) {
            $out .= $part['plain_text'] ?? '';
        }
        return $out;
    }

    private function PriorityRank(string $p): int
    {
        $map = ['ASAP' => 0, 'hohe Prio' => 1, 'mittlere Prio' => 2, 'niedrige Prio' => 3];
        return $map[$p] ?? 9;
    }

    private function NormalizeId(string $id): string
    {
        $id = trim($id);
        $id = preg_replace('/[^a-fA-F0-9]/', '', $id);
        return strtolower($id);
    }
}
